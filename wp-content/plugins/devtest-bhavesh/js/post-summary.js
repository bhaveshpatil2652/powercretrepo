jQuery(function($){
    $('.post-summary-button').on('click', function(){
        var $wrapper = $(this).closest('.post-summary-wrapper');
        var postId = $wrapper.data('post-id');
        var $status = $wrapper.find('.post-summary-status');
        var $result = $wrapper.find('.post-summary-result');

        $status.text(postSummaryData.loading);
        $result.attr('hidden', true).empty();

        $.ajax({
            type: 'POST',
            url: postSummaryData.ajaxUrl,
            dataType: 'json',
            data: {
                action: postSummaryData.action,
                post_id: postId,
                security: postSummaryData.nonce
            },
            success: function(response){
                if (response.success && response.data && response.data.summary) {
                    $status.empty();
                    $result.removeAttr('hidden').text(response.data.summary);
                } else {
                    $status.text(postSummaryData.errorText);
                }
            },
            error: function(){
                $status.text(postSummaryData.errorText);
            }
        });
    });
});