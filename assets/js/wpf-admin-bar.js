jQuery(document).ready(function($){
    $(document).on("input propertychange paste change", '.wpf_search_preview_tag', function(e) {
        var value = $(this).val().toLowerCase();
        var index = $(this).parent().index() + 1;
        var $li = $('#wp-admin-bar-wpf-default > li').slice(index);
        $li.hide();
        $li.filter(function() {
            var text = $(this).text().toLowerCase();
            return text.indexOf(value)>=0;
        }).show();
    
    });
});