/**
 * @file
 * atomeco behaviors.
 */
(function (Drupal) {

  'use strict';

  Drupal.behaviors.atomeco = {
    attach: function (context, settings) {
      // Utilisez context pour cibler les éléments dans le DOM
      const burger = once('menu-burger', '.menu-burger', context);
      const menu = once('menu', 'nav#block-atomeco-main-menu > ul', context);

      if (burger.length && menu.length) {
        burger[0].addEventListener('click', function() {
          menu[0].classList.toggle('menu-open');
          console.log('object');
        });
      }
    }
  };

} (Drupal));
