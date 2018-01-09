window.changedAuthorSelection = function() {
    var authorEl = $("#blog-reply-author");
    if (authorEl) {
        var author = authorEl.val();
        if (typeof author === 'undefined') {
            return;
        }
        if (author.length < 1) {
            $(".guest-comment-field").show(200);
            $("#blog-comment-name").attr('required', 'required');
            $("#blog-comment-email").attr('required', 'required');
        } else {
            $(".guest-comment-field").hide(200);
            $("#blog-comment-name").removeAttr('required');
            $("#blog-comment-email").removeAttr('required');
        }
    }
};

window.replyTo = function(commentId, author) {
    Airship.assertType(commentId, string);
    Airship.assertType(author, string);
    $("#reply-to").html(
        "<div class='blog-comment-label form-column'></div><div class='form-comment-field form-column'>" +
        "<input type='hidden' name='reply_to' value='" +
            Airship.e(commentId) +
        "' />" +
            "Replying to " +
                Airship.e(author, Airship.E_HTML) +
            " (Comment #" +
                Airship.e(commentId, Airship.E_HTML) +
            ")" +
        "</div>"
    );
};

window.getCommentForm = function(cabinURL) {
    var blogBody = $("#blog-post-body");
    $.post(
        cabinURL + "ajax/blog_comment_form",
        {
            "year": blogBody.data('year'),
            "month": blogBody.data('month'),
            "slug": blogBody.data('slug'),
            "csrf_token": $("body").data('ajaxtoken')
        },
        function (response) {
            $("#blog-comment-form-container").html(response);
        }
    );
};

window.loadComments = function(cabinURL, uniqueID) {
    $.post(
        cabinURL + "ajax/blog_load_comments",
        {
            "blogpost": uniqueID,
            "csrf_token": $("body").data('ajaxtoken')
        },
        function (response) {
            if (response.status === "OK") {
                $("#blog-comments-container").html(response.cached);
            }
        }
    );
};

$(document).ready(function() {
    window.changedAuthorSelection();
    $("#blog-reply-author").on('change', window.changedAuthorSelection);
    $(".reply-link").click(function() {
        window.replyTo(
            $(this).data('replyto'),
            $(this).data('author')
        );
    });
    var comment_wrapper = $("#blog_comments_wrapper");
    if (comment_wrapper.data('cached')) {
        window.loadComments(
            comment_wrapper.data('cabinurl'),
            comment_wrapper.data('uniqueid')
        );
        window.getCommentForm(comment_wrapper.data('cabinurl'));
    }
});
