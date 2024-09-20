/* global jQuery, Userfront, wp */
jQuery(document).ready(function ($) {
  function getUrlVars() {
    var vars = [],
      hash;
    var hashes = window.location.href
      .slice(window.location.href.indexOf("?") + 1)
      .split("&");
    for (var i = 0; i < hashes.length; i++) {
      hash = hashes[i].split("=");
      vars.push(hash[0]);
      vars[hash[0]] = hash[1];
    }
    return vars;
  }

  var error = getUrlVars()["error"];

  if (error === "no-wordpress-user") {
    $("#userfront-error").css("background", "#fdf2f2");
    $("#userfront-error").css("padding", "1rem");
    $("#userfront-error").css("margin", "1rem 0");
    $("#userfront-error").css("border-radius", "0.5rem");
    $("#userfront-error").css("color", "#9b1c1c");
    $("#userfront-error").css("font-size", "1rem");
    $("#userfront-error").css("display", "block");
    $("#userfront-error").css("text-align", "center");
    $("#userfront-error").css("max-width", "24rem");
    $("#userfront-error").text("Account does not exist.");
  }
});
