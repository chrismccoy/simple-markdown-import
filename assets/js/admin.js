/**
 * Simple Markdown Import - Admin Scripts
 */
jQuery(document).ready(function($) {
    
    /**
     * Import Method Toggle.
     * Switches between File Upload and Paste Textarea.
     */
    $('input[name="import_method"]').on('change', function() {
        var method = $(this).val();
        $('.smi-section').removeClass('active');
        
        if (method === 'upload') {
            $('#smi-upload-section').addClass('active');
            $('#markdown_file').prop('required', true);
            $('#markdown_paste').prop('required', false);
        } else if (method === 'paste') {
            $('#smi-paste-section').addClass('active');
            $('#markdown_file').prop('required', false);
            $('#markdown_paste').prop('required', true);
        }
    });

    /**
     * File Input Change.
     * Updates the styled label text with the selected filename.
     */
    $('#markdown_file').on('change', function() {
        var fileName = $(this).val().split('\\').pop();
        if(fileName) {
            $('#smi-file-name').text(fileName).css('color', '#000');
        } else {
            $('#smi-file-name').text(smiSettings.strings.noFile).css('color', '#646970');
        }
    });

    /**
     * Form Submission
     * Collects form data and sends to WordPress admin-ajax.php.
     */
    $('#smi-import-form').on('submit', function(e) {
        e.preventDefault();

        var $form = $(this);
        var $btn = $('#smi-submit-btn');
        var $spinner = $('#smi-spinner');
        var $notice = $('#smi-notice-area');
        var $console = $('#smi-console');

        if (!$('input[name="import_method"]:checked').length) {
            alert(smiSettings.strings.selectMethod);
            return;
        }

        $notice.hide().removeClass('success error').html('');
        $console.show().html('<div class="smi-log-line">' + smiSettings.strings.initializing + '</div>');
        $btn.prop('disabled', true);
        $spinner.addClass('is-active');

        var formData = new FormData(this);
        formData.append('action', 'smi_process_import');
        formData.append('security', smiSettings.nonce);

        $.ajax({
            url: smiSettings.ajaxUrl,
            type: 'POST',
            data: formData,
            contentType: false,
            processData: false,
            success: function(response) {
                $btn.prop('disabled', false);
                $spinner.removeClass('is-active');

                if (response.success) {
                    $notice.addClass('success').html('<p>' + response.data.message + '</p>').slideDown();
                    
                    if(response.data.logs) {
                        $.each(response.data.logs, function(index, value) {
                            $console.append('<div class="smi-log-line">' + value + '</div>');
                        });
                    }
                    
                    $form.find('input[type="text"], textarea').val('');
                    $form.find('input[type="file"]').val('');
                    $('#smi-file-name').text(smiSettings.strings.noFile);

                } else {
                    var errorMsg = response.data.message || smiSettings.strings.unknownError;
                    $notice.addClass('error').html('<p><strong>' + smiSettings.strings.errorLabel + '</strong> ' + errorMsg + '</p>').slideDown();
                    $console.append('<div class="smi-log-line" style="color: #ff6b6b;">FAILED: ' + errorMsg + '</div>');
                }
                
                $console.scrollTop($console[0].scrollHeight);
            },
            error: function(xhr, status, error) {
                $btn.prop('disabled', false);
                $spinner.removeClass('is-active');
                $notice.addClass('error').html('<p><strong>' + smiSettings.strings.serverError + '</strong> ' + error + '</p>').slideDown();
                $console.append('<div class="smi-log-line" style="color: #ff6b6b;">SERVER ERROR: ' + error + '</div>');
            }
        });
    });
});
