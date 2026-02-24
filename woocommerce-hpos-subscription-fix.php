<?php
/**
 * Plugin Name: WooCommerce HPOS Subscription Fix
 * Plugin URI:  https://github.com/renzojohnson/woocommerce-hpos-subscription-fix
 * Description: Fixes WooCommerce HPOS bug #50944 — orphaned subscriptions with parent_order_id=0 and customer_id=0 in wp_wc_orders. Three-layer defense: pre-INSERT normalizer, post-create safety net, and status-change backstop.
 * Version:     2026.02.24
 * Author:      Renzo Johnson
 * Author URI:  https://renzojohnson.com
 * License:     GPL-2.0-or-later
 * Requires at least: 6.0
 * Requires PHP: 8.1
 *
 * @see     https://github.com/woocommerce/woocommerce/issues/50944
 * @package WooCommerce_HPOS_Subscription_Fix
 */

declare(strict_types=1);

namespace WooCommerce\HPOSSubscriptionFix;

\defined( 'ABSPATH' ) || exit;

\add_action(
	'woocommerce_loaded',
	static function (): void {
		Plugin::boot();
	}
);

/**
 * Fixes WooCommerce HPOS bug where subscriptions are created with
 * parent_order_id=0 and customer_id=0 in the wp_wc_orders table.
 *
 * @see https://github.com/woocommerce/woocommerce/issues/50944
 */
final class Plugin {

	private const VERSION = '2026.02.24';

	private const REST_NAMESPACE = 'hpos-sub-fix';
	private const REST_ROUTE     = '/pair-subscription';

	private const CONTEXT_CREATE = 'create';

	private const ORDER_TYPE_SHOP_ORDER        = 'shop_order';
	private const ORDER_TYPE_SHOP_SUBSCRIPTION = 'shop_subscription';

	private const SUBSCRIPTION_STATUS_ACTIVE = 'active';

	private const WC_STATUS_COMPLETED  = 'wc-completed';
	private const WC_STATUS_PROCESSING = 'wc-processing';

	private const NOTES_SOURCE_CHECKOUT   = 'checkout';
	private const NOTES_SOURCE_BACKSTOP   = 'completion-backstop';

	private const HPOS_FILTER_ROWS = 'woocommerce_orders_table_datastore_db_rows_for_order';

	private const ACTION_SUB_CREATED = 'woocommerce_checkout_subscription_created';

	private const ACTION_ORDER_COMPLETED  = 'woocommerce_order_status_completed';
	private const ACTION_ORDER_PROCESSING = 'woocommerce_order_status_processing';

	private const FILTER_SUB_STATUS_COL = 'woocommerce_subscription_list_table_column_status_content';
	private const ADMIN_SCREEN_SUBSCRIPTIONS = 'woocommerce_page_wc-orders--shop_subscription';
	private const REST_NONCE_ACTION          = 'wp_rest';

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Bootstrap singleton.
	 */
	public static function boot(): void {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
	}

	/**
	 * Private constructor.
	 */
	private function __construct() {
		$has_subscriptions = \class_exists( 'WC_Subscriptions' );
		$has_api_manager   = \class_exists( 'WC_AM_Order' ) && \method_exists( '\\WC_AM_Order', 'instance' );

		if ( ! $has_subscriptions ) {
			return;
		}

		// Strategy A: normalize HPOS row data before INSERT.
		\add_filter( self::HPOS_FILTER_ROWS, [ $this, 'normalize_hpos_subscription_rows' ], 999, 3 );

		// Strategy B: immediate post-create safety net during checkout.
		\add_action( self::ACTION_SUB_CREATED, [ $this, 'checkout_subscription_created' ], 5, 3 );

		// Strategy C: completion/processing backstop.
		\add_action( self::ACTION_ORDER_COMPLETED, [ $this, 'recover_on_order_status' ], 20, 1 );
		\add_action( self::ACTION_ORDER_PROCESSING, [ $this, 'recover_on_order_status' ], 20, 1 );

		// Pair UI: manual pairing tools for admin.
		\add_action( 'rest_api_init', [ $this, 'register_routes' ] );
		\add_filter( self::FILTER_SUB_STATUS_COL, [ $this, 'add_pair_link' ], 10, 3 );
		\add_action( 'admin_footer', [ $this, 'render_script' ] );
	}

	/**
	 * Register manual pairing REST endpoint.
	 */
	public function register_routes(): void {
		\register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'pair_subscription' ],
				'permission_callback' => [ $this, 'can_pair_subscription' ],
				'args'                => [
					'subscription_id' => [
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					],
					'order_id'        => [
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					],
				],
			]
		);
	}

	/**
	 * REST permission callback.
	 *
	 * @param \WP_REST_Request $request Request object.
	 *
	 * @return bool
	 */
	public function can_pair_subscription( \WP_REST_Request $request ): bool {
		if ( ! \current_user_can( 'manage_woocommerce' ) ) {
			return false;
		}

		$nonce = (string) $request->get_header( 'X-WP-Nonce' );

		return '' !== $nonce && false !== \wp_verify_nonce( $nonce, self::REST_NONCE_ACTION );
	}

	/**
	 * Strategy A: normalize subscription create payload before HPOS insert.
	 *
	 * @param array<mixed> $rows    HPOS row definitions.
	 * @param mixed        $order   Order object.
	 * @param string       $context Save context.
	 *
	 * @return array<mixed>
	 */
	public function normalize_hpos_subscription_rows( array $rows, $order, string $context ): array {
		if ( self::CONTEXT_CREATE !== $context ) {
			return $rows;
		}

		if ( ! $order instanceof \WC_Abstract_Order ) {
			return $rows;
		}

		if ( self::ORDER_TYPE_SHOP_SUBSCRIPTION !== $order->get_type() ) {
			return $rows;
		}

		try {
			$orders_row_index = $this->find_wc_orders_row_index( $rows );
			if ( null === $orders_row_index ) {
				return $rows;
			}

			$orders_row = $rows[ $orders_row_index ];
			if ( ! \is_array( $orders_row ) || ! isset( $orders_row['data'] ) || ! \is_array( $orders_row['data'] ) ) {
				return $rows;
			}

			$parent_order_id = isset( $orders_row['data']['parent_order_id'] ) ? \absint( $orders_row['data']['parent_order_id'] ) : 0;
			$customer_id     = isset( $orders_row['data']['customer_id'] ) ? \absint( $orders_row['data']['customer_id'] ) : 0;
			$needs_parent    = 0 === $parent_order_id;
			$needs_customer  = 0 === $customer_id;

			if ( ! $needs_parent && ! $needs_customer ) {
				return $rows;
			}

			$resolved_parent_id = $parent_order_id;
			if ( $needs_parent ) {
				$resolved_parent_id = \absint( $order->get_parent_id() );
				if ( 0 === $resolved_parent_id ) {
					$resolved_parent_id = $this->get_post_parent_id( \absint( $order->get_id() ) );
				}
			}

			$resolved_customer_id = $customer_id;
			if ( $needs_customer && $resolved_parent_id > 0 ) {
				$resolved_customer_id = $this->get_customer_id_from_order( $resolved_parent_id );
			}

			$did_change = false;

			if ( $needs_parent && $resolved_parent_id > 0 ) {
				$orders_row['data']['parent_order_id'] = $resolved_parent_id;
				$did_change                            = true;
			}

			if ( $needs_customer && $resolved_customer_id > 0 ) {
				$orders_row['data']['customer_id'] = $resolved_customer_id;
				$did_change                        = true;
			}

			if ( $did_change ) {
				if ( ! isset( $orders_row['format'] ) || ! \is_array( $orders_row['format'] ) ) {
					$orders_row['format'] = [];
				}

				$orders_row['format']['parent_order_id'] = '%d';
				$orders_row['format']['customer_id']     = '%d';

				$rows[ $orders_row_index ] = $orders_row;

				\wc_get_logger()->info(
					\sprintf(
						'Strategy A: normalized HPOS row before INSERT. subscription_id=%d parent_order_id=%d customer_id=%d',
						\absint( $order->get_id() ),
						$resolved_parent_id,
						$resolved_customer_id
					),
					[ 'source' => 'hpos-subscription-fix' ]
				);
			}
		} catch ( \Throwable $exception ) {
			return $rows;
		}

		return $rows;
	}

	/**
	 * Strategy B: checkout safety-net to repair parent/customer link.
	 *
	 * @param mixed              $subscription  Subscription object.
	 * @param mixed              $order         Parent order object from checkout hook.
	 * @param \WC_Cart|mixed $recurring_cart Recurring cart from checkout.
	 */
	public function checkout_subscription_created( $subscription, $order, mixed $recurring_cart ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( ! $subscription instanceof \WC_Subscription || ! $order instanceof \WC_Order ) {
			return;
		}

		try {
			$current_parent_id = \absint( $subscription->get_parent_id() );
			$current_customer  = \absint( $subscription->get_customer_id() );

			$expected_parent_id = \absint( $order->get_id() );
			if ( 0 === $expected_parent_id ) {
				$expected_parent_id = $this->get_post_parent_id( \absint( $subscription->get_id() ) );
			}

			$expected_customer_id = \absint( $order->get_customer_id() );
			if ( 0 === $expected_customer_id && $expected_parent_id > 0 ) {
				$expected_customer_id = $this->get_customer_id_from_order( $expected_parent_id );
			}

			$did_change = false;

			if ( $expected_parent_id > 0 && $current_parent_id !== $expected_parent_id ) {
				$subscription->set_parent_id( $expected_parent_id );
				$did_change = true;
			}

			if ( $expected_customer_id > 0 && $current_customer !== $expected_customer_id ) {
				$subscription->set_customer_id( $expected_customer_id );
				$did_change = true;
			}

			if ( ! $did_change ) {
				return;
			}

			$subscription->save();
			$this->add_repair_note( $subscription, $expected_parent_id, $expected_customer_id, self::NOTES_SOURCE_CHECKOUT );

			\wc_get_logger()->warning(
				\sprintf(
					'Strategy B: repaired subscription post-create. subscription_id=%d parent_order_id=%d customer_id=%d (was parent=%d customer=%d)',
					\absint( $subscription->get_id() ),
					$expected_parent_id,
					$expected_customer_id,
					$current_parent_id,
					$current_customer
				),
				[ 'source' => 'hpos-subscription-fix' ]
			);
		} catch ( \Throwable $exception ) {
			return;
		}
	}

	/**
	 * Strategy C: recover orphan links on status transitions.
	 *
	 * @param int|string $order_id Order ID.
	 */
	public function recover_on_order_status( $order_id ): void {
		$order_id = \absint( $order_id );
		if ( $order_id <= 0 ) {
			return;
		}

		$order = \wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$expected_customer_id = \absint( $order->get_customer_id() );
		$subscription_ids     = $this->get_subscription_ids_for_parent_order( $order_id );

		foreach ( $subscription_ids as $subscription_id ) {
			$subscription = \function_exists( 'wcs_get_subscription' ) ? \wcs_get_subscription( $subscription_id ) : null;
			if ( ! $subscription instanceof \WC_Subscription ) {
				continue;
			}

			$current_parent   = \absint( $subscription->get_parent_id() );
			$current_customer = \absint( $subscription->get_customer_id() );

			$target_parent   = $order_id;
			$target_customer = $current_customer > 0 ? $current_customer : $expected_customer_id;

			$did_change = false;

			if ( $current_parent !== $target_parent ) {
				$subscription->set_parent_id( $target_parent );
				$did_change = true;
			}

			if ( $target_customer > 0 && $current_customer !== $target_customer ) {
				$subscription->set_customer_id( $target_customer );
				$did_change = true;
			}

			if ( $did_change ) {
				try {
					$subscription->save();
					$this->add_repair_note( $subscription, $target_parent, $target_customer, self::NOTES_SOURCE_BACKSTOP );

					\wc_get_logger()->warning(
						\sprintf(
							'Strategy C: repaired subscription on status change. order_id=%d subscription_id=%d parent_order_id=%d customer_id=%d (was parent=%d customer=%d)',
							$order_id,
							$subscription_id,
							$target_parent,
							$target_customer,
							$current_parent,
							$current_customer
						),
						[ 'source' => 'hpos-subscription-fix' ]
					);
				} catch ( \Throwable $exception ) {
					continue;
				}
			}
		}

		if ( ! \class_exists( 'WC_AM_Order' ) || ! \method_exists( '\\WC_AM_Order', 'instance' ) ) {
			return;
		}

		if ( $this->order_has_api_license( $order_id ) ) {
			return;
		}

		try {
			/** @var object $api_manager_order */
			$api_manager_order = \WC_AM_Order::instance();
			if ( \is_object( $api_manager_order ) && \method_exists( $api_manager_order, 'update_order' ) ) {
				$api_manager_order->update_order( $order_id );
			}
		} catch ( \Throwable $exception ) {
			return;
		}
	}

	/**
	 * Add pair link to orphan subscriptions in subscriptions list table.
	 *
	 * @param string      $column_content Existing column HTML.
	 * @param mixed       $subscription   Subscription object.
	 * @param array<mixed> $actions        Row actions.
	 *
	 * @return string
	 */
	public function add_pair_link( string $column_content, $subscription, array $actions ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( ! $this->is_orphan( $subscription ) ) {
			return $column_content;
		}

		$matching_order = $this->find_matching_order( $subscription );
		if ( ! $matching_order instanceof \WC_Order ) {
			return $column_content;
		}

		$pair_link = \sprintf(
			'<a href="#" class="hpos-pair-orphan" data-tip="%d|%d" title="Link to Order #%s (%s)">Pair</a>',
			\absint( $subscription->get_id() ),
			\absint( $matching_order->get_id() ),
			\esc_attr( $matching_order->get_order_number() ),
			\esc_attr( (string) $matching_order->get_billing_email() )
		);

		return \str_replace(
			'<div class="row-actions">',
			'<div class="row-actions"><span class="pair">' . $pair_link . '</span> | ',
			$column_content
		);
	}

	/**
	 * Render admin JS for pair action.
	 */
	public function render_script(): void {
		$screen = \get_current_screen();
		if ( ! $screen || self::ADMIN_SCREEN_SUBSCRIPTIONS !== $screen->id ) {
			return;
		}

		$rest_url = \esc_url( \rest_url( self::REST_NAMESPACE . self::REST_ROUTE ) );
		$nonce    = \wp_create_nonce( self::REST_NONCE_ACTION );
		?>
		<script>
		(function() {
			var restUrl = '<?php echo \esc_js( $rest_url ); ?>';
			var nonce = '<?php echo \esc_js( $nonce ); ?>';

			document.querySelectorAll('.hpos-pair-orphan').forEach(function(link) {
				link.addEventListener('click', function(e) {
					e.preventDefault();

					var dataTip = this.getAttribute('data-tip');
					if (!dataTip) {
						return;
					}

					var parts = dataTip.split('|');
					var subId = parts[0];
					var orderId = parts[1];

					if (!confirm('Pair subscription #' + subId + ' with order #' + orderId + '?\n\nThis will:\n- Link subscription to order\n- Set customer from order\n- Activate subscription\n- Generate API license')) {
						return;
					}

					var el = this;
					el.textContent = 'Pairing...';
					el.style.pointerEvents = 'none';

					fetch(restUrl, {
						method: 'POST',
						headers: {
							'Content-Type': 'application/json',
							'X-WP-Nonce': nonce
						},
						body: JSON.stringify({
							subscription_id: parseInt(subId, 10),
							order_id: parseInt(orderId, 10)
						})
					})
					.then(function(r) { return r.json(); })
					.then(function(data) {
						if (data.success) {
							var td = el.closest('td');
							var badge = td ? td.querySelector('.order-status') : null;
							if (badge) {
								badge.className = badge.className.replace('status-pending', 'status-active');
								var span = badge.querySelector('span');
								if (span) {
									span.textContent = 'Active';
								}
							}
							var wrap = el.closest('.pair');
							if (wrap) {
								wrap.remove();
							}
							alert('Paired successfully!\n\n' + data.message);
						} else {
							alert('Error: ' + (data.message || 'Unknown error'));
							el.textContent = 'Pair';
							el.style.pointerEvents = '';
						}
					})
					.catch(function(err) {
						alert('Request failed: ' + err.message);
						el.textContent = 'Pair';
						el.style.pointerEvents = '';
					});
				});
			});
		})();
		</script>
		<?php
	}

	/**
	 * Handle manual pair operation.
	 *
	 * @param \WP_REST_Request $request Request object.
	 *
	 * @return \WP_REST_Response
	 */
	public function pair_subscription( \WP_REST_Request $request ): \WP_REST_Response {
		$subscription_id = \absint( $request->get_param( 'subscription_id' ) );
		$order_id        = \absint( $request->get_param( 'order_id' ) );

		$subscription = \function_exists( 'wcs_get_subscription' ) ? \wcs_get_subscription( $subscription_id ) : null;
		$order        = \wc_get_order( $order_id );

		if ( ! $subscription instanceof \WC_Subscription || ! $order instanceof \WC_Order ) {
			return new \WP_REST_Response(
				[
					'success' => false,
					'message' => 'Subscription or order not found',
				],
				404
			);
		}

		if ( ! $this->is_orphan( $subscription ) ) {
			return new \WP_REST_Response(
				[
					'success' => false,
					'message' => 'Subscription is not an orphan',
				],
				400
			);
		}

		$customer_id = \absint( $order->get_customer_id() );
		if ( $customer_id <= 0 ) {
			return new \WP_REST_Response(
				[
					'success' => false,
					'message' => 'Order has no customer ID',
				],
				400
			);
		}

		try {
			$subscription->set_customer_id( $customer_id );
			$subscription->set_parent_id( $order_id );
			$subscription->update_status( self::SUBSCRIPTION_STATUS_ACTIVE );
			$subscription->save();

			$subscription->add_order_note(
				\sprintf(
					'Subscription paired with order #%s. Customer ID: %d. (HPOS bug #50944 fix)',
					$order->get_order_number(),
					$customer_id
				),
				false,
				true
			);

			$license_generated = false;
			if ( \class_exists( 'WC_AM_Order' ) && \method_exists( '\\WC_AM_Order', 'instance' ) ) {
				/** @var object $api_manager_order */
				$api_manager_order = \WC_AM_Order::instance();
				if ( \is_object( $api_manager_order ) && \method_exists( $api_manager_order, 'update_order' ) ) {
					$api_manager_order->update_order( $order_id );
					$license_generated = true;
				}
			}

			return new \WP_REST_Response(
				[
					'success' => true,
					'message' => \sprintf(
						'Subscription #%d paired with Order #%s. Customer: %d. Status: Active.%s',
						$subscription_id,
						$order->get_order_number(),
						$customer_id,
						$license_generated ? ' License generated.' : ''
					),
				],
				200
			);
		} catch ( \Throwable $exception ) {
			\wc_get_logger()->error(
				\sprintf(
					'Pair subscription failed: %s | subscription_id=%d order_id=%d user=%d',
					$exception->getMessage(),
					$subscription_id,
					$order_id,
					\get_current_user_id()
				),
				[ 'source' => 'hpos-subscription-fix' ]
			);

			return new \WP_REST_Response(
				[
					'success' => false,
					'message' => 'An internal error occurred while pairing. Check logs.',
				],
				500
			);
		}
	}

	/**
	 * Determine if a subscription is orphaned.
	 *
	 * @param mixed $subscription Subscription object.
	 *
	 * @return bool
	 */
	private function is_orphan( $subscription ): bool {
		if ( ! $subscription instanceof \WC_Subscription ) {
			return false;
		}

		return 0 === \absint( $subscription->get_customer_id() ) && 0 === \absint( $subscription->get_parent_id() );
	}

	/**
	 * Find probable matching order for orphan pair action.
	 *
	 * @param \WC_Subscription $subscription Subscription object.
	 *
	 * @return \WC_Order|null
	 */
	private function find_matching_order( \WC_Subscription $subscription ): ?\WC_Order {
		global $wpdb;

		$sub_created = $subscription->get_date_created();
		if ( ! $sub_created ) {
			return null;
		}

		$sub_timestamp = $sub_created->getTimestamp();
		$window_start  = \gmdate( 'Y-m-d H:i:s', $sub_timestamp - 60 );
		$window_end    = \gmdate( 'Y-m-d H:i:s', $sub_timestamp + 60 );

		$table = $wpdb->prefix . 'wc_orders';

		$order_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id
				FROM {$table}
				WHERE type = %s
				AND status IN ( %s, %s )
				AND date_created_gmt BETWEEN %s AND %s
				AND customer_id > 0
				ORDER BY ABS(TIMESTAMPDIFF(SECOND, date_created_gmt, %s)) ASC
				LIMIT 1",
				self::ORDER_TYPE_SHOP_ORDER,
				self::WC_STATUS_COMPLETED,
				self::WC_STATUS_PROCESSING,
				$window_start,
				$window_end,
				$sub_created->format( 'Y-m-d H:i:s' )
			)
		);

		return ! empty( $order_id ) ? \wc_get_order( \absint( $order_id ) ) : null;
	}

	/**
	 * Get subscription IDs whose wp_posts.post_parent points to an order.
	 *
	 * @param int $order_id Parent order ID.
	 *
	 * @return array<int>
	 */
	private function get_subscription_ids_for_parent_order( int $order_id ): array {
		global $wpdb;

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID
				FROM {$wpdb->posts}
				WHERE post_type = %s
				AND post_parent = %d",
				self::ORDER_TYPE_SHOP_SUBSCRIPTION,
				$order_id
			)
		);

		if ( ! \is_array( $ids ) || empty( $ids ) ) {
			return [];
		}

		return \array_map( 'absint', $ids );
	}

	/**
	 * Resolve parent post ID for a post.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return int
	 */
	private function get_post_parent_id( int $post_id ): int {
		if ( $post_id <= 0 ) {
			return 0;
		}

		global $wpdb;

		$parent_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_parent FROM {$wpdb->posts} WHERE ID = %d LIMIT 1",
				$post_id
			)
		);

		return \absint( $parent_id );
	}

	/**
	 * Resolve customer ID from an order.
	 *
	 * @param int $order_id Order ID.
	 *
	 * @return int
	 */
	private function get_customer_id_from_order( int $order_id ): int {
		if ( $order_id <= 0 ) {
			return 0;
		}

		$order = \wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return 0;
		}

		return \absint( $order->get_customer_id() );
	}

	/**
	 * Find the wc_orders row index in HPOS row payload.
	 *
	 * @param array<mixed> $rows HPOS rows.
	 *
	 * @return int|null
	 */
	private function find_wc_orders_row_index( array $rows ): ?int {
		foreach ( $rows as $index => $row ) {
			if ( ! \is_array( $row ) || empty( $row['table'] ) ) {
				continue;
			}

			$table = (string) $row['table'];
			if ( \preg_match( '/(?:^|_)wc_orders$/', $table ) ) {
				return (int) $index;
			}
		}

		return null;
	}

	/**
	 * Check if API Manager license/resource rows already exist for an order.
	 *
	 * @param int $order_id Order ID.
	 *
	 * @return bool
	 */
	private function order_has_api_license( int $order_id ): bool {
		if ( $order_id <= 0 ) {
			return false;
		}

		global $wpdb;

		$table_name = $wpdb->prefix . 'wc_am_api_resource';

		$table_exists = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name )
		);

		if ( $table_name !== $table_exists ) {
			return false;
		}

		$resource_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT api_resource_id FROM {$table_name} WHERE order_id = %d LIMIT 1",
				$order_id
			)
		);

		return ! empty( $resource_id );
	}

	/**
	 * Append an order note for repaired subscription links.
	 *
	 * @param \WC_Subscription $subscription Subscription object.
	 * @param int              $parent_id    Parent order ID.
	 * @param int              $customer_id  Customer ID.
	 * @param string           $source       Repair source label.
	 */
	private function add_repair_note( \WC_Subscription $subscription, int $parent_id, int $customer_id, string $source ): void {
		$subscription->add_order_note(
			\sprintf(
				'HPOS subscription linkage repaired (%s). parent_order_id=%d, customer_id=%d. Plugin v%s.',
				$source,
				$parent_id,
				$customer_id,
				self::VERSION
			),
			false,
			true
		);
	}
}
