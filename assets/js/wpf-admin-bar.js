jQuery(document).ready(function($){

	// Appends tag ID to all URLs on site so filter doesn't get reset when moving between pages

	function getUrlVars() {
	    var vars = [], hash;
	    var hashes = window.location.href.slice(window.location.href.indexOf('?') + 1).split('&');
	    for(var i = 0; i < hashes.length; i++) {
	        hash = hashes[i].split('=');
	        vars.push(hash[0]);
	        vars[hash[0]] = hash[1];
	    }
	    return vars;
	}

	var tag = getUrlVars()["wpf_tag"];

	if(tag) {

		$('a:not(#wpadminbar a)').each(function() {
			this.href += (/\?/.test(this.href) ? '&' : '?') + 'wpf_tag=' + tag;
		});

	}

});