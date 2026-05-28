<?php if (!current_user_can('manage_options')) wp_die(__('Unauthorized.')); ?>
<div>
    <h2><?php esc_html_e('Export Data', 'space-booking'); ?></h2>
    <p><?php esc_html_e('Download JSON with all Spaces, Packages, Extras + meta.', 'space-booking'); ?></p>
    <button id="sb-export-btn"
        class="button button-primary"><?php esc_html_e('Export JSON', 'space-booking'); ?></button>
    <p class="description">
        <?php esc_html_e('File: space-booking-data.json (all post meta included)', 'space-booking'); ?></p>

    <hr>

    <h2><?php esc_html_e('Import Data', 'space-booking'); ?></h2>
    <p><?php esc_html_e('Upload JSON to replace/create data.', 'space-booking'); ?></p>
    <form id="sb-import-form" enctype="multipart/form-data">
        <p>
            <input type="file" id="sb-import-file" name="json_file" accept=".json" required>
        </p>
        <label>
            <input type="checkbox" id="sb-delete-existing" name="delete_existing" value="1">
            <?php esc_html_e('Delete existing data before import', 'space-booking'); ?>
        </label>
        <p class="description">
            <?php esc_html_e('WARNING: This will permanently delete all Spaces/Packages/Extras.', 'space-booking'); ?>
        </p>
        <button type="submit" id="sb-import-btn" class="button button-primary"
            disabled><?php esc_html_e('Import JSON', 'space-booking'); ?></button>
    </form>
    <div id="sb-import-status"></div>
</div>

<style>
#sb-import-status {
    margin-top: 20px;
    padding: 10px;
    border-radius: 4px;
}

.sb-success {
    background: #d4edda;
    color: #155724;
    border: 1px solid #c3e6cb;
}

.sb-error {
    background: #f8d7da;
    color: #721c24;
    border: 1px solid #f5c6cb;
}
</style>

<script>
jQuery(document).ready(function($) {
    const nonce = '<?php echo wp_create_nonce('sb_export_import'); ?>';

    // Enable import button when file selected
    $('#sb-import-file').on('change', function() {
        $('#sb-import-btn').prop('disabled', !this.files.length);
    });

    $('#sb-export-btn').click(function() {
        const link = document.createElement('a');
        link.href = ajaxurl + '?action=sb_export_data&_wpnonce=' + nonce;
        link.download = 'space-booking-data.json';
        link.click();
    });

    $('#sb-import-form').on('submit', function(e) {
        e.preventDefault();
        const formData = new FormData(this);
        formData.append('_wpnonce', nonce);
        formData.append('action', 'sb_import_data');

        $('#sb-import-btn').prop('disabled', true).text('Importing...');
        $('#sb-import-status').removeClass('sb-success sb-error').html('');

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(res) {
                console.log('AJAX response:', res); // Debug

                if (res.success) {
                    $('#sb-import-status').addClass('sb-success').html('✓ ' + (res.data
                        .message || res.data));
                    $('#sb-import-form')[0].reset();
                    $('#sb-import-btn').prop('disabled', true);
                } else {
                    $('#sb-import-status').addClass('sb-error').html('✗ ' + (res.data
                        .message || res.data));
                }
            },
            error: function() {
                $('#sb-import-status').addClass('sb-error').html('Request failed.');
            },
            complete: function() {
                $('#sb-import-btn').prop('disabled', true).text('Import JSON');
            }
        });
    });
});
</script>