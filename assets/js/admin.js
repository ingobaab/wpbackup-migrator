/**
 * WPBackup Migrator admin scripts
 */

jQuery(document).ready(function ($) {
    // Toggle password visibility
    $(".wpbackup-toggle-password").on("click", function () {
        var input = $("#wpbackup-migration-key");
        var icon = $(this).find("span.dashicons");

        if (input.attr("type") === "password") {
            input.attr("type", "text");
            icon.removeClass("dashicons-visibility").addClass("dashicons-hidden");
        } else {
            input.attr("type", "password");
            icon.removeClass("dashicons-hidden").addClass("dashicons-visibility");
        }
    });

    // Copy migration key to clipboard
    $(".wpbackup-copy-clipboard").on("click", function () {
        var key = $("#wpbackup-migration-key");
        var keyValue = key.val();
        var $button = $(this);
        var $buttonText = $button.find('span:last-child');
        var originalText = $buttonText.text();

        // Try to use Clipboard API (more modern and works in secure contexts)
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(keyValue)
                .then(function () {
                    showCopiedFeedback();
                })
                .catch(function () {
                    copyWithExecCommand();
                });
        } else {
            copyWithExecCommand();
        }

        function showCopiedFeedback() {
            $buttonText.text('Copied');
            $button.addClass('copied');

            setTimeout(function () {
                $buttonText.text(originalText);
                $button.removeClass('copied');
            }, 3000);
        }

        function copyWithExecCommand() {
            var originalType = key.attr('type');
            // Make sure text is visible during copy
            if (originalType === 'password') {
                key.attr('type', 'text');
            }

            key.select();
            var success = document.execCommand("copy");
            // unselect the text
            key.blur();

            // Restore original input type
            if (originalType === 'password') {
                key.attr('type', 'password');
            }

            if (success) {
                showCopiedFeedback();
            }
        }
    });
});
