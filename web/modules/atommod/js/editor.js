(function ($, Drupal, window, document) {

    'use strict';
  
    // To understand behaviors, see https://drupal.org/node/756722#behaviors
    Drupal.behaviors.admin = {
      attach: function (context, settings) {

if (!$(context).data('initialized')) {
$(context).data('initialized', true);
$(context).find(".toolbar-icon-entity-user-collection").each(function () {
    //supression droits et roles users
  var firstpan = $(".toolbar-icon-entity-user-collection").first().siblings(".toolbar-menu").find("li:nth-child(2)");
  firstpan.css('display','none');
  var span2 = $(".toolbar-icon-entity-user-collection").first().siblings(".toolbar-menu").find("li:nth-child(3)");
  span2.css('display','none');
  console.log(firstpan);
});



$(context).find(".toolbar-icon-system-admin-structure").each(function () {
    // Suppression des droits taxonomie
    var toolbarMenuItems = $(this).first().siblings(".toolbar-menu").find("li ul li");
    toolbarMenuItems.find("ul").children().css('display', 'none');
    toolbarMenuItems.css('background-image', 'none');
    console.log(toolbarMenuItems);
  });

}
    }
};

})(jQuery, Drupal, this, this.document);