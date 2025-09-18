<?php
/*
Plugin Name: Simple Export of Orders for Marketing Campaigns
Description: Exports customer phone and email from completed WooCommerce orders via WP-CLI.
Version: 1.0
Author: Eduard V. Doloc
Author URI: https://uprise.ro
*/

if ( defined( 'WP_CLI' ) && WP_CLI ) {

	class Simple_Marketing_Export_Command {

		/**
		 * Export phone and email from completed WooCommerce orders.
		 *
		 * ## EXAMPLES
		 *
		 *     wp simple-marketing export
		 *
		 * @when after_wp_load
		 */
		public function export() {
			if ( ! class_exists( 'WooCommerce' ) ) {
				WP_CLI::error( 'WooCommerce is not active.' );
			}

			$args = array(
				'status' => 'completed',
//				'limit'  => 10000,
                'limit' => -1,
				'return' => 'ids',
			);

			$order_ids = wc_get_orders( $args );

			if ( empty( $order_ids ) ) {
				WP_CLI::warning( 'No completed orders found.' );

				return;
			}

			$upload_dir = plugin_dir_path( __FILE__ ) . 'exports/';
			if ( ! file_exists( $upload_dir ) ) {
				mkdir( $upload_dir, 0755, true );
			}

//            $filename = $upload_dir . 'marketing-export-' . date( 'Y-m-d-H-i-s' ) . '.csv';
			$filename = $upload_dir . 'marketing-export-' . wp_generate_password( 16, false ) . '-' . date( 'Y-m-d-H-i-s' ) . '.csv';
			$file     = fopen( $filename, 'w' );

			fputcsv( $file, [ 'phone', 'email' ] );

			$progress = \WP_CLI\Utils\make_progress_bar( 'Exporting orders', count( $order_ids ) );

			foreach ( $order_ids as $order_id ) {
				$order = wc_get_order( $order_id );
				// Try to get via order object first (HPOS), fallback to postmeta if needed
				if ( $order ) {
					$email = $order->get_billing_email();
					$phone = $order->get_billing_phone();
				} else {
					$email = get_post_meta( $order_id, '_billing_email', true );
					$phone = get_post_meta( $order_id, '_billing_phone', true );
				}

				fputcsv( $file, [ $phone, $email ] );
				$progress->tick();
			}

			$progress->finish();
			fclose( $file );

			WP_CLI::success( "Exported to $filename" );
		}
	}

	WP_CLI::add_command( 'simple-marketing', 'Simple_Marketing_Export_Command' );
}
