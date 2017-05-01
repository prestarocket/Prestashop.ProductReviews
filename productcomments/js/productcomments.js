function fnSubmitNewMessage() {
	url_options = '?';
    if (!productcomments_url_rewrite) {
    	url_options = '&';
    }

	var url = productcomments_controller_url.replace(/&amp;/g, "&");
	$.ajax({
		url: url + url_options + 'action=add_comment&secure_key=' + secure_key + '&rand=' + new Date().getTime(),
		data: $('#id_new_comment_form').serialize(),
		type: 'POST',
		headers: { "cache-control": "no-cache" },
		dataType: "json",
		beforeSend: function() {
			$('#iloading-icon').css('display', 'inline-block');
		},
		success: function(data) {
			if (data.result) {
				if (data.html == '') {
					document.getElementById('id_new_comment_form').scrollIntoView();
					$('#new_comment_form_success').css('display', 'block');
				} else {
					document.getElementById('product_comments_block_tab').scrollIntoView();
					$('#product_comments_block_tab').prepend(data.html);

					$('#comment_title').val('');
					$('#content').val('');
				}
			} else {
				$('#new_comment_form_error').css('display', 'block');
				$('#new_comment_form_error ul').html('');
				$.each(data.errors, function(index, value) {
					$('#new_comment_form_error ul').append('<li>'+value+'</li>');
				});
				document.getElementById('id_new_comment_form').scrollIntoView();
				$('#new_comment_form_error').slideDown('slow');
			}
			$('#iloading-icon').css('display', 'none');
		},
		error: function() {
			$('#iloading-icon').css('display', 'none');
		}
	});
}
function icloseClick(e) {
	/*$(e).parent().css('display', 'none');*/
	$(e).parent().fadeToggle("slow");
}
function iFnLike(e) {
	var id_product_comment = $(e).data('id-product-comment');
	var is_usefull = $(e).data('is-usefull');
	var count = $(e).find('.count-ilike').text();

	var url = productcomments_controller_url.replace(/&amp;/g, "&");
	$.ajax({
		url: url + '?rand=' + new Date().getTime(),
		data: {
			id_product_comment: id_product_comment,
			action: 'comment_is_usefull',
			value: is_usefull
		},
		type: 'POST',
		headers: { "cache-control": "no-cache" },
		success: function(result){
			
		}
	});
}
function iFnReport(e) {
	if (confirm(confirm_report_message)) {
		var idProductComment = $(e).data('id-product-comment');
		var parent = $(e).parent();
		var url = productcomments_controller_url.replace(/&amp;/g, "&");
		$.ajax({
			url: url + '?rand=' + new Date().getTime(),
			data: {
				id_product_comment: idProductComment,
				action: 'report_abuse'
			},
			type: 'POST',
			headers: { "cache-control": "no-cache" },
			success: function(result) {
				parent.fadeOut('slow', function() {
					parent.remove();
				});
			}
		});
	}
}