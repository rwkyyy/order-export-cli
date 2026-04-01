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

			$batch_size = 2000;
			$page       = 1;

			$total_orders = wc_get_orders( [
				'status'   => 'completed',
				'limit'    => 1,
				'paginate' => true,
			] );

			if ( empty( $total_orders->total ) ) {
				WP_CLI::warning( 'No completed orders found.' );
				return;
			}

			$total_orders_count = $total_orders->total;

			$upload_dir = plugin_dir_path( __FILE__ ) . 'exports/';
			if ( ! file_exists( $upload_dir ) ) {
				mkdir( $upload_dir, 0755, true );
			}

			$filename = $upload_dir . 'marketing-export-' . wp_generate_password( 16, false ) . '-' . date( 'Y-m-d-H-i-s' ) . '.csv';
			$file     = fopen( $filename, 'w' );
//			fputcsv( $file, [ 'phone', 'email' ] );
			fputcsv( $file, [ 'phone' ] );

			$progress = \WP_CLI\Utils\make_progress_bar( 'Exporting orders', $total_orders_count );

			do {
				$orders = wc_get_orders( [
					'status' => 'completed',
					'limit'  => $batch_size,
					'page'   => $page,
					'return' => 'ids',
				] );

				if ( empty( $orders ) ) {
					break;
				}

				foreach ( $orders as $order_id ) {
					$order = wc_get_order( $order_id );
					if ( $order instanceof WC_Order_Refund ) {
						$progress->tick();
						continue;
					}

					if ( $order ) {
//						$email = $order->get_billing_email();
						$phone = $order->get_billing_phone();
					} else {
//						$email = get_post_meta( $order_id, '_billing_email', true );
						$phone = get_post_meta( $order_id, '_billing_phone', true );
					}

//					fputcsv( $file, [ $phone, $email ] );
					fputcsv( $file, [ $phone ] );
					$progress->tick();

					//avoid memory spikes for high order no - comment for speed!
//					unset( $order );
				}

				WP_CLI::log( "Completed batch $page" );
				$page++;
			} while ( true );

			$progress->finish();
			fclose( $file );
			WP_CLI::success( "Exported to $filename" );
		}
	}

	WP_CLI::add_command( 'simple-marketing', 'Simple_Marketing_Export_Command' );
}