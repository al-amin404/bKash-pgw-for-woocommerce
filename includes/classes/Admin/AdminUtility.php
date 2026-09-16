<?php
/**
 * Admin Utility
 *
 * @category    Utility
 * @package     bkash-pgw-for-woocommerce
 * @author      Md. Shahnawaz Ahmed <shahnawaz.ahmed@bkash.com>
 * @copyright   Copyright 2022 bKash Limited. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 * @link        https://bkash.com
 */

namespace bKash\PGW\Admin;

use bKash\PGW\Utils;

class AdminUtility {
	private static $instance;

	public static function getInstance(): AdminUtility {
		if ( ! isset( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public static function loadTable(
		string $title,
		string $tbl_name,
		array $columns = array(),
		array $filters = array(),
		array $actions = array()
	) {
		global $wpdb;
		$primaryColumn = 'ID';
		$table_name    = Utils::safeSqlString( $wpdb->prefix . $tbl_name );
		$pageNumber    = Utils::hasGetField( "pagenum" ) ? absint( Utils::safeGetValue( "pagenum" ) ) : 1;
		$limit         = BKASH_FW_TABLE_LIMIT;
		$offset        = ( $pageNumber - 1 ) * $limit;

		$queryValue[] = 0; // primary columnn value for %d
		$whereQuery   = "$primaryColumn > %d ";

		$whereParts = array();

		if ( count( $filters ) > 0 ) {
			foreach ( $filters as $key => $filter ) {
				$normalized = self::normalizeFilter( $key, $filter );
				$input      = Utils::safeGetValue( $normalized['key'] );

				if ( '' === $input ) {
					continue;
				}

				if ( 'select' === $normalized['type'] ) {
					$allowed_values = array_map( 'strval', array_keys( $normalized['options'] ) );
					if ( ! in_array( $input, $allowed_values, true ) ) {
						continue;
					}
				}

				$column_name  = str_replace( '`', '', Utils::safeSqlString( $normalized['key'] ) );
				$whereParts[] = '`' . $column_name . '` ' . ( 'select' === $normalized['type'] ? '= %s' : 'LIKE %s' );
				$queryValue[] = ( 'select' === $normalized['type'] ) ? $input : '%' . $wpdb->esc_like( $input ) . '%';
			}

			if ( count( $whereParts ) > 0 ) {
				$whereQuery .= 'AND ' . implode( ' AND ', $whereParts ) . ' ';
			}
		}

		$countColumnValue = $queryValue; // for counting purpose keeping where info only
		$queryValue[]     = $offset; // value of offset as %d
		$queryValue[]     = $limit; // value of limit as %d

		$sqlQuery   = "SELECT * from $table_name where $whereQuery ORDER BY `ID` DESC limit %d, %d";
		$countQuery = "SELECT count(*) as total from $table_name where $whereQuery";

		$rows     = $wpdb->get_results(
			$wpdb->prepare( $sqlQuery, $queryValue )
		);
		$rowcount = $wpdb->num_rows ?? 0;

		$total        = $wpdb->get_var(
			$wpdb->prepare( $countQuery, $countColumnValue )
		);
		$num_of_pages = ceil( $total / $limit );

		$page_links = paginate_links(
			array(
				'base'      => add_query_arg( 'pagenum', '%#%' ),
				'format'    => '',
				'prev_text' => __( '&laquo;', 'bkash-pgw-for-woocommerce' ),
				'next_text' => __( '&raquo;', 'bkash-pgw-for-woocommerce' ),
				'total'     => $num_of_pages,
				'current'   => $pageNumber
			)
		);

		include_once "pages/table.php";
	}

	/**
	 * Normalize a table filter definition into a consistent shape.
	 *
	 * @param string       $key    Query parameter / column name.
	 * @param string|array $filter Filter label or config array.
	 *
	 * @return array{key: string, label: string, type: string, empty_label: string, options: array}
	 */
	public static function normalizeFilter( string $key, $filter ): array {
		if ( is_array( $filter ) ) {
			$options = array();
			if ( isset( $filter['options'] ) && is_array( $filter['options'] ) ) {
				$options = $filter['options'];
			}

			$label       = isset( $filter['label'] ) ? (string) $filter['label'] : $key;
			$empty_label = isset( $filter['empty_label'] ) ? (string) $filter['empty_label'] : sprintf(
				/* translators: %s: filter label, e.g. Status */
				__( 'All %s', 'bkash-pgw-for-woocommerce' ),
				$label
			);

			return array(
				'key'         => $key,
				'label'       => $label,
				'type'        => isset( $filter['type'] ) ? (string) $filter['type'] : 'search',
				'empty_label' => $empty_label,
				'options'     => $options,
			);
		}

		return array(
			'key'         => $key,
			'label'       => (string) $filter,
			'type'        => 'search',
			'empty_label' => '',
			'options'     => array(),
		);
	}

	public static function getBKashOptions( string $plugin_id, string $key ) {
		$option_value = false;
		$options      = get_option( 'woocommerce_' . $plugin_id . '_settings' );

		if ( ( ! is_array( $options ) || ! isset( $options[ $key ] ) ) && BKASH_FW_LEGACY_PLUGIN_SLUG !== $plugin_id ) {
			$options = get_option( 'woocommerce_' . BKASH_FW_LEGACY_PLUGIN_SLUG . '_settings' );
		}

		if ( is_array( $options ) && isset( $options[ $key ] ) ) {
			if ( $options[ $key ] === 'yes' || $options[ $key ] === 'no' ) {
				$option_value = $options[ $key ] === 'yes';
			} else {
				$option_value = $options[ $key ];
			}
		}

		return $option_value;
	}

	public static function validateResponse( array $apiResp = array(), array $specificField = array() ): array {
		$feedback = array(
			'valid'    => false,
			'message'  => '',
			'response' => []
		);


		if ( isset( $apiResp['status_code'], $apiResp['response'] ) && $apiResp['status_code'] === 200 ) {
			$response = $apiResp['response'];
			if ( is_string( $response ) ) {
				$response = json_decode( $response, true );
			}

			if ( isset( $response['errorMessage'] ) ) {
				$feedback['message'] = $response['errorMessage'];
			} elseif ( isset( $response['statusMessage'] ) && $response['statusMessage'] !== 'Successful' ) {
				$feedback['message'] = $response['statusMessage'];
			} else {
				if ( count( $specificField ) > 0 ) {
					if ( $response[ key( $specificField ) ] === $specificField[ key( $specificField ) ] ) {
						$feedback['valid'] = true;
					} else {
						$feedback['message'] = key( $specificField ) . " is not present or not matching with the value " . $specificField[ key( $specificField ) ];
					}
				} else {
					$feedback['valid'] = true;
				}

				$feedback['response'] = $response;
			}
		} else {
			$feedback['message'] = "Action cannot be performed at bKash server right now, try again";
		}

		return $feedback;
	}


	public static function redirectToPage( string $url = "" ) {
		wp_safe_redirect( esc_url( $url ) );
	}

	public static function addFlashNotice( string $notice = "", string $type = "warning", bool $dismissible = true ) {
		$notices = get_option( "bKash_flash_notices", array() );

		$dismissible_text = ( $dismissible ) ? "is-dismissible" : "";

		$notices[] = array(
			"notice"      => $notice,
			"type"        => $type,
			"dismissible" => $dismissible_text
		);

		update_option( "bKash_flash_notices", $notices );
	}


	/**
	 * @param string $str
	 * @param string $separator
	 *
	 * @return string
	 */
	public static function keyToLabel( string $str, string $separator = "_" ): string {
		$str = str_replace( $separator, " ", $str );

		return ucwords( $str );
	}

	/**
	 * @param $row
	 * @param array $column
	 *
	 * @return bool
	 */
	public static function ifRefundValueIsPresent( $row, array $column ): bool {
		return isset( $column[0] ) && str_contains( strtolower( $column[0] ), "refund" ) && ! empty( $row->{$column[0]} );
	}

	public static function setStatusColor( string $status ): string {
		$color = "#909090";

		if ( stripos( $status, "cancel" ) !== false ) {
			$color = "#f4a938";
		} elseif ( stripos( $status, "complete" ) !== false ) {
			$color = "#1dae5b";
		} elseif ( stripos( $status, "fail" ) !== false ) {
			$color = "#ff4136";
		} elseif ( stripos( $status, "auth" ) !== false ) {
			$color = "#0b608a";
		}

		return $color;
	}
}
