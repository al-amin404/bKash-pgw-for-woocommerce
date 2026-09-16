<?php
/**
 * Table
 *
 * @category    Page
 * @package     bkash-pgw-for-woocommerce
 * @author      Md. Shahnawaz Ahmed <shahnawaz.ahmed@bkash.com>
 * @copyright   Copyright 2022 bKash Limited. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 * @link        https://bkash.com
 */

use bKash\PGW\Admin\AdminUtility;
use bKash\PGW\Utils;

?>
	<div class="wrap abs">
		<h2>
			<?php
			esc_html_e( $title ?? 'List', 'bkash-pgw-for-woocommerce' );
			?>
		</h2>

		<!-- Search Form -->
		<div class="tablenav top">
			<div class="alignleft actions">
				<?php
				$page_name = Utils::safeGetValue( 'page' );
				if ( '' === $page_name ) {
					$page_name = BKASH_FW_ADMIN_PAGE_SLUG;
				}
				?>
				<form class="bkash-pgw-filters" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" method="get">
					<input type="hidden" name="page" value="<?php echo esc_attr( $page_name ); ?>"/>
					<?php
					if ( isset( $filters ) && count( $filters ) > 0 ) {
						foreach ( $filters as $key => $filter ) {
							$normalized = AdminUtility::normalizeFilter( $key, $filter );
							$old_input  = Utils::safeGetValue( $normalized['key'] );
							?>
							<label>
								<span class="screen-reader-text"><?php echo esc_html( $normalized['label'] ); ?></span>
								<?php if ( 'select' === $normalized['type'] ) : ?>
									<select name="<?php echo esc_attr( $normalized['key'] ); ?>">
										<option value=""><?php echo esc_html( $normalized['empty_label'] ); ?></option>
										<?php foreach ( $normalized['options'] as $option_value => $option_label ) : ?>
											<option
												value="<?php echo esc_attr( (string) $option_value ); ?>"
												<?php selected( $old_input, (string) $option_value ); ?>
											>
												<?php echo esc_html( (string) $option_label ); ?>
											</option>
										<?php endforeach; ?>
									</select>
								<?php else : ?>
									<input
										type="search"
										name="<?php echo esc_attr( $normalized['key'] ); ?>"
										value="<?php echo esc_attr( $old_input ); ?>"
										placeholder="<?php echo esc_attr( $normalized['label'] ); ?>"
									/>
								<?php endif; ?>
							</label>
							<?php
						}
					}
					?>
					<button type="submit" class="button"><?php esc_html_e( 'Search', 'bkash-pgw-for-woocommerce' ); ?></button>
				</form>
			</div>
			<br class="clear">
		</div>

		<!-- Table -->
		<table id="transaction-list-table" class='wp-list-table widefat fixed striped posts' aria-describedby="
		<?php
		esc_attr_e( $title );
		?>
		">
			<!-- Column Headers -->
			<tr>
				<?php
				if ( isset( $columns ) ) {
					foreach ( array_keys( $columns ) as $table_head ) {
						?>
						<th class='manage-column ss-list-width' scope='col'>
							<?php
							esc_html_e( $table_head );
							?>
						</th>
						<?php
					}

					if ( isset( $actions ) && count( $actions ) > 0 ) {
						?>
						<th class='manage-column ss-list-width' scope='col'>
							Actions
						</th>
						<?php
					}
				}
				?>
			</tr>

			<?php
			if ( isset( $rows ) && count( (array) $rows ) > 0 ) {
				foreach ( $rows as $row ) {
					?>
					<!-- Items -->
					<tr>
						<?php
						foreach ( $columns as $column ) {
							// if want to show multiple value in a single column
							if ( is_array( $column ) ) {
								?>
								<td class='manage-column ss-list-width'>
									<?php
									if ( AdminUtility::ifRefundValueIsPresent( $row, $column ) ) {
										?>
										<span class="bKash-chip">Refunded</span>
										<?php
									}

									foreach ( $column as $item ) {
										if ( ! empty( $row->{$item} ) ) {
											?>
											<p>
												<?php
												$value = AdminUtility::keyToLabel( $item ) . ': ' . $row->{$item};
												esc_html_e( $value, 'bkash-pgw-for-woocommerce' );
												?>
											</p>
											<?php
										}
									}
									?>
								</td>
								<?php
							} else { // single value in a column
								?>
								<td class='manage-column ss-list-width'>
									<?php
									if ( str_contains( strtolower( $column ), 'status' ) ) {
										$statusColor = esc_attr( AdminUtility::setStatusColor( $row->{$column} ) );
										?>
										<span class="bKash-chip" style="background:
										<?php
										echo esc_attr( $statusColor )
										?>
										 !important;">
										<?php
											esc_html_e( $row->{$column}, 'bkash-pgw-for-woocommerce' );
										?>
											</span>
										<?php
									} else {
										esc_html_e( $row->{$column}, 'bkash-pgw-for-woocommerce' );
									}
									?>
								</td>
								<?php
							}
						}
						?>
						<!-- Action Buttons -->
						<?php
						if ( isset( $actions ) && count( $actions ) > 0 ) {
							?>
							<td class='manage-column ss-list-width'>
								<?php
								foreach ( $actions as $action ) {
									$actionUrl = esc_url(
										admin_url(
											'admin.php?page=' . BKASH_FW_ADMIN_PAGE_SLUG . '/'
											. ( $action['page'] ?? '' ) . '&action='
											. ( $action['action'] ?? '' ) . '&id=' . $row->ID
										)
									);
									if ( isset( $action['confirm'] ) && $action['confirm'] ) {
										$clickEvent = 'onclick="return confirm(\'Are you sure to do this?\');"';
									}
									?>
									<a
										<?php
										echo wp_kses_post( $clickEvent ) ?? '';
										?>
										href="<?php echo esc_attr( $actionUrl ); ?>">
										<?php
										esc_html_e( $action['title'] ?? '' )
										?>
									</a>
									<?php
								}
								?>
							</td>
							<?php
						}
						?>
					</tr>
					<?php
				}
			} else {
				echo wp_kses_post( "<tr><td colspan='" . esc_html( count( $columns ) ) . "'>No records found</td></tr>" );
			}
			?>
		</table>
	</div>

<?php
if ( isset( $page_links ) && $page_links ) {
	?>
	<div class="tablenav pagination-links" style="width: 99%;">
		<div class="tablenav-pages" style="margin: 1em 0">
		<?php
			echo wp_kses_post( $page_links )
		?>
			</div>
	</div>
	<?php
}
?>
