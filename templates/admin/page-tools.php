<?php defined('ABSPATH') || exit; ?>
<div class="wrap sb-admin-tools">
    <h1><?php esc_html_e('Tools', 'space-booking'); ?></h1>

    <div class="sb-tabs">
        <button class="sb-tab-btn active"
            data-tab="export-import"><?php esc_html_e('Export/Import', 'space-booking'); ?></button>
        <button class="sb-tab-btn"
            data-tab="customize"><?php esc_html_e('Customize Fields', 'space-booking'); ?></button>
    </div>

    <div id="export-import-tab" class="sb-tab-content active">
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
            <p><input type="file" id="sb-import-file" name="json_file" accept=".json" required></p>
            <label><input type="checkbox" id="sb-delete-existing" name="delete_existing"
                    value="1"><?php esc_html_e('Delete existing data before import', 'space-booking'); ?></label>
            <p class="description">
                <?php esc_html_e('WARNING: This will permanently delete all Spaces/Packages/Extras.', 'space-booking'); ?>
            </p>
            <button type="submit" id="sb-import-btn" class="button button-primary"
                disabled><?php esc_html_e('Import JSON', 'space-booking'); ?></button>
        </form>
        <div id="sb-import-status"></div>
    </div>

    <div id="customize-tab" class="sb-tab-content">
        <div id="sb-customize-fields">
            <h3><?php esc_html_e('Customer Form Fields', 'space-booking'); ?></h3>
            <p class="description">
                <?php esc_html_e('Add, edit, reorder customer fields for Step 4. Changes saved live.', 'space-booking'); ?>
            </p>

            <div id="sb-fields-repeater">
                <?php
                $service = new \SpaceBooking\Services\CustomerFieldsService();
                $fields = $service->get_fields();
                foreach ($fields as $i => $field):
                    ?>
                <div class="sb-field-row" data-index="<?php echo $i; ?>">
                    <div class="sb-field-col">
                        <label><?php esc_html_e('Handle/Key', 'space-booking'); ?> <span
                                class="required">*</span></label>
                        <input type="text" name="fields[<?php echo $i; ?>][key]"
                            value="<?php echo esc_attr($field['key']); ?>" required maxlength="50" />
                    </div>
                    <div class="sb-field-col">
                        <label><?php esc_html_e('Label', 'space-booking'); ?> <span class="required">*</span></label>
                        <input type="text" name="fields[<?php echo $i; ?>][label]"
                            value="<?php echo esc_html($field['label']); ?>" required maxlength="100" />
                    </div>
                    <div class="sb-field-col">
                        <label><?php esc_html_e('Type', 'space-booking'); ?> <span class="required">*</span></label>
                        <select name="fields[<?php echo $i; ?>][type]" required>
                            <option value="text" <?php selected($field['type'], 'text'); ?>>Text</option>
                            <option value="email" <?php selected($field['type'], 'email'); ?>>Email</option>
                            <option value="tel" <?php selected($field['type'], 'tel'); ?>>Phone</option>
                            <option value="number" <?php selected($field['type'], 'number'); ?>>Number</option>
                            <option value="textarea" <?php selected($field['type'], 'textarea'); ?>>Textarea</option>
                            <option value="checkbox" <?php selected($field['type'], 'checkbox'); ?>>
                                Checkbox</option>
                            <option value="radio" <?php selected($field['type'], 'radio'); ?>>Radio Buttons</option>
                            <option value="select" <?php selected($field['type'], 'select'); ?>>Dropdown</option>
                        </select>
                    </div>
                    <div class="sb-field-col">
                        <label><input type="checkbox" name="fields[<?php echo $i; ?>][required]"
                                <?php checked($field['required'] ?? false); ?> /><?php esc_html_e('Required', 'space-booking'); ?></label>
                    </div>
                    <div class="sb-field-col">
                        <label><?php esc_html_e('Placeholder', 'space-booking'); ?></label>
                        <input type="text" name="fields[<?php echo $i; ?>][placeholder]"
                            value="<?php echo esc_attr($field['placeholder'] ?? ''); ?>" maxlength="100" />
                    </div>
                    <div class="sb-field-col">
                        <label><?php esc_html_e('Default Value', 'space-booking'); ?></label>
                        <input type="text" name="fields[<?php echo $i; ?>][default]"
                            value="<?php echo esc_attr($field['default'] ?? ''); ?>" />
                    </div>
                    <div class="sb-field-col sb-options-col">
                        <label><?php esc_html_e('Options (JSON array)', 'space-booking'); ?></label>
                        <textarea name="fields[<?php echo $i; ?>][options]" rows="2"
                            placeholder='["Option 1", "Option 2"]'><?php echo esc_textarea(json_encode($field['options'] ?? [], JSON_UNESCAPED_UNICODE)); ?></textarea>
                        <small><?php esc_html_e('For radio/select only', 'space-booking'); ?></small>
                    </div>
                    <div class="sb-field-actions">
                        <button type="button" class="button-link sb-remove-field" title="Remove">×</button>
                        <div class="sb-drag-handle">⋮⋮</div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <p>
                <button type="button" id="sb-add-field"
                    class="button"><?php esc_html_e('Add Field', 'space-booking'); ?></button>
                <button type="button" id="sb-save-fields"
                    class="button button-primary"><?php esc_html_e('Save Changes', 'space-booking'); ?></button>
            </p>

            <div id="sb-fields-status"></div>

            <h3><?php esc_html_e('Preview', 'space-booking'); ?></h3>
            <div id="sb-fields-preview" style="background:#f9f9f9; padding:20px; border-radius:6px;"></div>
        </div>
    </div>

    <script>
    jQuery(document).ready(function($) {
        const nonce = '<?php echo wp_create_nonce('sb_export_import'); ?>';
        const ajaxurl = '<?php echo admin_url('admin-ajax.php'); ?>';
        let fieldIndex = <?php echo count($fields); ?>;

        // Tab switching
        $('.sb-tab-btn').on('click', function() {
            $('.sb-tab-btn').removeClass('active');
            $('.sb-tab-content').removeClass('active');
            $(this).addClass('active');
            $('#' + $(this).data('tab') + '-tab').addClass('active');
        });

        // Export/Import JS (from page-export-import.php)
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
                    if (res.success) {
                        $('#sb-import-status').addClass('sb-success').html('✓ ' + (res.data
                            .message || res.data));
                        $('#sb-import-form')[0].reset();
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

        // Fields JS (from page-bookings.php)
        // Drag & Drop functionality
        let draggedRow = null;
        const repeater = document.getElementById('sb-fields-repeater');

        // Drag start on handle - SIMPLIFIED jQuery only, no native draggable
        $(document).on('mousedown', '.sb-drag-handle', function(e) {
            draggedRow = this.closest('.sb-field-row');
            $(draggedRow).addClass('sb-dragging');

            // Clone for smooth dragging
            const ghost = draggedRow.cloneNode(true);
            ghost.classList.add('sb-drag-ghost');
            ghost.style.position = 'absolute';
            ghost.style.zIndex = '10000';
            ghost.style.opacity = '0.8';
            ghost.style.pointerEvents = 'none';
            ghost.style.width = draggedRow.offsetWidth + 'px';
            document.body.appendChild(ghost);

            let lastMouseX = e.clientX;
            let lastMouseY = e.clientY;

            const moveHandler = (me) => {
                const deltaX = me.clientX - lastMouseX;
                const deltaY = me.clientY - lastMouseY;
                const rect = ghost.getBoundingClientRect();
                ghost.style.left = rect.left + deltaX + 'px';
                ghost.style.top = rect.top + deltaY + 'px';
                lastMouseX = me.clientX;
                lastMouseY = me.clientY;
            };

            const upHandler = () => {
                document.removeEventListener('mousemove', moveHandler);
                document.removeEventListener('mouseup', upHandler);
                ghost.remove();

                // Find drop target
                const dropTarget = document.elementFromPoint(e.clientX, e.clientY)?.closest(
                    '.sb-field-row');
                if (dropTarget && dropTarget !== draggedRow) {
                    if (dropTarget.querySelector('.sb-drag-handle')) {
                        dropTarget.parentNode.insertBefore(draggedRow, dropTarget);
                    } else {
                        dropTarget.parentNode.insertBefore(draggedRow, dropTarget.nextSibling);
                    }
                    updateFieldIndexes();
                    updatePreviewFromDOM();
                }

                $(draggedRow)?.removeClass('sb-dragging');
                draggedRow = null;
            };

            document.addEventListener('mousemove', moveHandler);
            document.addEventListener('mouseup', upHandler);
            e.preventDefault();
            return false;
        });

        // Drop & reorder
        repeater.addEventListener('drop', function(e) {
            e.preventDefault();
            const dropTarget = e.target.closest('.sb-field-row');
            if (dropTarget && dropTarget !== draggedRow) {
                if ($(e.target).hasClass('sb-drag-handle') || $(e.target).closest('.sb-drag-handle')
                    .length) {
                    $(dropTarget).before(draggedRow);
                } else {
                    $(dropTarget).after(draggedRow);
                }
                updateFieldIndexes();
                updatePreviewFromDOM();
            }
            cleanupDrag();
        });

        // Touch support for mobile
        let touchStartY = 0;
        $(document).on('touchstart', '.sb-drag-handle', function(e) {
            touchStartY = e.originalEvent.touches[0].clientY;
        });

        $(document).on('touchmove', '.sb-drag-handle', function(e) {
            if (Math.abs(e.originalEvent.touches[0].clientY - touchStartY) > 10) {
                // Long press to drag (simplified)
                setTimeout(() => {
                    draggedRow = this.closest('.sb-field-row');
                    $(draggedRow).addClass('sb-dragging');
                }, 500);
            }
        });

        // Helper functions
        function updateFieldIndexes() {
            $('#sb-fields-repeater .sb-field-row').each(function(i) {
                $(this).attr('data-index', i);
                $(this).find('input, select, textarea').each(function() {
                    let name = $(this).attr('name');
                    if (name) {
                        name = name.replace(/\[(\d+)\]/, `[${i}]`);
                        $(this).attr('name', name);
                    }
                });
            });
        }

        function updatePreviewFromDOM() {
            const fieldsData = [];
            $('#sb-fields-repeater .sb-field-row').each(function() {
                const row = $(this);
                const field = {
                    key: row.find('[name*="[key]"]').val(),
                    label: row.find('[name*="[label]"]').val(),
                    type: row.find('[name*="[type]"]').val(),
                    required: row.find('[name*="[required]"]').is(':checked'),
                    placeholder: row.find('[name*="[placeholder]"]').val(),
                    default: row.find('[name*="[default]"]').val(),
                    options: row.find('[name*="[options]"]').val()
                };
                fieldsData.push(field);
            });
            updatePreview(fieldsData);
        }

        function cleanupDrag() {
            $('.sb-field-row').removeClass('sb-dragging drag-over');
            if (draggedRow) {
                draggedRow.draggable = false;
                draggedRow = null;
            }
        }

        // Existing handlers with drag integration
        $('#sb-add-field').on('click', function() {
            const newFieldHtml = `
                <div class="sb-field-row" data-index="${fieldIndex}">
                    <div class="sb-field-col"><label>Key <span class="required">*</span></label><input type="text" name="fields[${fieldIndex}][key]" required maxlength="50" /></div>
                    <div class="sb-field-col"><label>Label <span class="required">*</span></label><input type="text" name="fields[${fieldIndex}][label]" required maxlength="100" /></div>
                    <div class="sb-field-col"><label>Type <span class="required">*</span></label>
                        <select name="fields[${fieldIndex}][type]" required>
                            <option value="text">Text</option><option value="email">Email</option><option value="tel">Phone</option>
                            <option value="number">Number</option><option value="textarea">Textarea</option><option value="checkbox">Checkbox</option>
                            <option value="radio">Radio</option><option value="select">Dropdown</option>
                        </select>
                    </div>
                    <div class="sb-field-col"><label><input type="checkbox" name="fields[${fieldIndex}][required]" /> Required</label></div>
                    <div class="sb-field-col"><label>Placeholder</label><input type="text" name="fields[${fieldIndex}][placeholder]" maxlength="100" /></div>
                    <div class="sb-field-col"><label>Default</label><input type="text" name="fields[${fieldIndex}][default]" /></div>
                    <div class="sb-field-col sb-options-col"><label>Options (JSON)</label><textarea name="fields[${fieldIndex}][options]" rows="2" placeholder='["Opt1","Opt2"]'></textarea><small>Radio/Select only</small></div>
                    <div class="sb-field-actions"><button type="button" class="button-link sb-remove-field">×</button><div class="sb-drag-handle">⋮⋮</div></div>
                </div>`;
            $('#sb-fields-repeater').append(newFieldHtml);
            fieldIndex++;
            updateFieldIndexes(); // NEW: Fix indexes after add
        });

        $(document).on('click', '.sb-remove-field', function() {
            $(this).closest('.sb-field-row').remove();
            updateFieldIndexes(); // NEW: Fix indexes after remove
            updatePreviewFromDOM();
        });

        $('#sb-save-fields').on('click', function() {
            updateFieldIndexes(); // Ensure correct order
            const fieldsData = [];
            $('#sb-fields-repeater .sb-field-row').each(function() {
                const row = $(this);
                const field = {
                    key: row.find('[name*="[key]"]').val(),
                    label: row.find('[name*="[label]"]').val(),
                    type: row.find('[name*="[type]"]').val(),
                    required: row.find('[name*="[required]"]').is(':checked'),
                    placeholder: row.find('[name*="[placeholder]"]').val(),
                    default: row.find('[name*="[default]"]').val(),
                    options: row.find('[name*="[options]"]').val()
                };
                fieldsData.push(field);
            });
            if (fieldsData.length === 0) {
                $('#sb-fields-status').html('<span class="error">At least one field required</span>');
                return;
            }
            $.post(ajaxurl, {
                action: 'sb_save_customer_fields',
                fields: JSON.stringify(fieldsData),
                _wpnonce: nonce
            }, function(res) {
                if (res.success) {
                    $('#sb-fields-status').html('<span style="color:green">✓ ' + res.data
                        .message + '</span>');
                    updatePreview(fieldsData);
                } else {
                    $('#sb-fields-status').html('<span class="error">✗ ' + res.data +
                        '</span>');
                }
            });
        });

        // Init indexes and preview
        updateFieldIndexes();

        function updatePreview(fields) {
            let preview = '';
            fields.forEach(function(field) {
                preview += '<div class="sb-field-preview"><label>' + field.label + (field.required ?
                    ' *' : '') + '</label>';
                if (field.type === 'textarea') {
                    preview += '<textarea placeholder="' + (field.placeholder || '') +
                        '" style="width:300px;height:60px"></textarea>';
                } else if (field.type === 'checkbox') {
                    preview += '<input type="checkbox">';
                } else if (field.type === 'radio' || field.type === 'select') {
                    preview += field.type === 'radio' ? '<label><input type="radio"> Opt1</label>' :
                        '<select><option>Opt1</option></select>';
                } else {
                    preview += '<input type="' + field.type + '" placeholder="' + (field.placeholder ||
                        '') + '" style="width:300px" />';
                }
                preview += '</div>';
            });
            $('#sb-fields-preview').html(preview);
        }
        <?php if (!empty($fields)): ?>
        updatePreview(<?php echo json_encode($fields); ?>);
        <?php endif; ?>

        // Init drag & drop
        updateFieldIndexes();
    });
    </script>

    <style>
    /* Existing styles from page-bookings.php + export-import */
    .sb-tabs {
        display: flex;
        border-bottom: 1px solid #c3c4c7;
        margin: 0 0 20px;
    }

    .sb-tab-btn {
        background: none;
        border: none;
        padding: 12px 24px;
        cursor: pointer;
        font-size: 14px;
        color: #50575e;
        border-bottom: 2px solid transparent;
    }

    .sb-tab-btn.active {
        color: #1d2327;
        border-bottom-color: #2271b1;
    }

    .sb-tab-content {
        display: none;
    }

    .sb-tab-content.active {
        display: block;
    }

    .sb-field-col {
        display: flex;
        flex-direction: column;
    }

    #sb-import-status {
        margin-top: 20px;
        padding: 10px;
        border-radius: 4px;
    }

    #sb-import-status.sb-success {
        background: #d4edda;
        color: #155724;
        border: 1px solid #c3e6cb;
    }

    #sb-import-status.sb-error {
        background: #f8d7da;
        color: #721c24;
        border: 1px solid #f5c6cb;
    }

    #sb-customize-fields .sb-field-row {
        display: grid;
        grid-template-columns: 1fr 1fr 150px 80px 1fr 1fr 2fr 60px;
        gap: 10px;
        padding: 15px;
        background: #f9f9f9;
        margin-bottom: 10px;
        border-radius: 4px;
        align-items: end;
    }

    #sb-customize-fields label {
        font-weight: 600;
        font-size: 13px;
        margin-bottom: 4px;
        display: block;
    }

    #sb-customize-fields input:not([type="checkbox"]),
    #sb-customize-fields select,
    #sb-customize-fields textarea {
        width: 100%;
        padding: 6px;
        border: 1px solid #ddd;
        border-radius: 3px;
        font-size: 13px;
    }

    #sb-customize-fields .sb-field-col {
        display: flex;
        flex-direction: column;
    }

    #sb-customize-fields .sb-field-actions {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 5px;
    }

    #sb-customize-fields .sb-drag-handle {
        cursor: grab;
        font-size: 20px;
        user-select: none;
    }

    #sb-customize-fields .sb-drag-handle:active {
        cursor: grabbing;
    }

    /* Drag & Drop Visual Feedback */
    .sb-field-row.sb-dragging {
        opacity: 0.7;
        transform: rotate(3deg) scale(1.02);
        box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        z-index: 1000;
    }

    .sb-field-row.drag-over {
        border: 2px dashed #2271b1;
        background: #e3f2fd;
    }

    #sb-customize-fields .required {
        color: #d63638;
    }

    #sb-customize-fields .sb-options-col small {
        font-size: 11px;
        color: #666;
    }

    .sb-field-preview {
        margin-bottom: 15px;
    }

    .sb-field-preview label {
        font-weight: 600;
    }

    .sb-field-preview input,
    .sb-field-preview textarea {
        border: 1px solid #ccc;
        padding: 8px;
        border-radius: 4px;
    }

    @media (max-width: 1200px) {
        #sb-customize-fields .sb-field-row {
            grid-template-columns: 1fr 1fr 1fr 1fr;
        }

        #sb-customize-fields .sb-options-col {
            grid-column: 1 / -1;
        }

        #sb-customize-fields .sb-field-actions {
            grid-column: -1;
            justify-self: end;
        }
    }
    </style>
</div>