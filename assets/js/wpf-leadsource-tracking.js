/*!
 * JavaScript Cookie v2.2.1
 * https://github.com/js-cookie/js-cookie
 * Copyright 2006, 2015 Klaus Hartl & Fagner Brack
 * Released under the MIT license
 * Update plugin name to CookiesWPF
 * @preserve
*/
!function(e){var t,i,_;"function"==typeof define&&define.amd&&(define(e),t=!0),"object"==typeof exports&&(module.exports=e(),t=!0),t||(i=window.CookiesWPF,(_=window.CookiesWPF=e()).noConflict=function(){return window.CookiesWPF=i,_})}(function(){function a(){for(var e=0,t={};e<arguments.length;e++){var i,_=arguments[e];for(i in _)t[i]=_[i]}return t}function r(e){return e.replace(/(%[0-9A-Z]{2})+/g,decodeURIComponent)}return function e(u){function n(){}function i(e,t,i){if("undefined"!=typeof document){"number"==typeof(i=a({path:"/"},n.defaults,i)).expires&&(i.expires=new Date(+new Date+864e5*i.expires)),i.expires=i.expires?i.expires.toUTCString():"";try{var _=JSON.stringify(t);/^[\{\[]/.test(_)&&(t=_)}catch(e){}t=u.write?u.write(t,e):encodeURIComponent(String(t)).replace(/%(23|24|26|2B|3A|3C|3E|3D|2F|3F|40|5B|5D|5E|60|7B|7D|7C)/g,decodeURIComponent),e=encodeURIComponent(String(e)).replace(/%(23|24|26|2B|5E|60|7C)/g,decodeURIComponent).replace(/[\(\)]/g,escape);var c,o="";for(c in i)i[c]&&(o+="; "+c,!0!==i[c]&&(o+="="+i[c].split(";")[0]));return document.cookie=e+"="+t+o}}function t(e,t){if("undefined"!=typeof document){for(var i={},_=document.cookie?document.cookie.split("; "):[],c=0;c<_.length;c++){var o=_[c].split("="),n=o.slice(1).join("=");t||'"'!==n.charAt(0)||(n=n.slice(1,-1));try{var a=r(o[0]),n=(u.read||u)(n,a)||r(n);if(t)try{n=JSON.parse(n)}catch(e){}if(i[a]=n,e===a)break}catch(e){}}return e?i[e]:i}}return n.set=i,n.get=function(e){return t(e,!1)},n.getJSON=function(e){return t(e,!0)},n.remove=function(e,t){i(e,"",a(t,{expires:-1}))},n.defaults={},n.withConverter=e,n}(function(){})}),


jQuery(document).ready(function($){

    function getParameterByName( name ) {
        name = name.replace(/[\[\]]/g, '\\$&');
        var regex = new RegExp('[?&]' + name + '(=([^&#]*)|&|#|$)'),
            results = regex.exec(location.search);
        if (!results) return null;
        if (!results[2]) return '';
        return decodeURIComponent(results[2].replace(/\+/g, ' '));
    }

	var params = [ 'leadsource', 'utm_source', 'utm_campaign', 'utm_medium', 'utm_term', 'utm_content', 'gclid' ];
	var cookie = {};

	$.each( params, function( index, param ) {

		var value = getParameterByName( param );

		if ( value ) {
            cookie[ param ] = value;
		}

	} );

    if ( Object.keys( cookie ).length > 0 ) {
        CookiesWPF.set( 'wpf_leadsource', cookie );
    }

} );