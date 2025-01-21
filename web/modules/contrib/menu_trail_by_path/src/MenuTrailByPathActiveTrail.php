<?php

namespace Drupal\menu_trail_by_path;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Menu\MenuActiveTrail;
use Drupal\Core\Menu\MenuLinkManagerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Url;
use Drupal\menu_trail_by_path\Path\PathHelperInterface;
use Drupal\Core\Path\PathValidatorInterface;
use Drupal\Core\Routing\RequestContext;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\system\Entity\Menu;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;

/**
 * Overrides the class for the file entity normalizer from HAL.
 */
class MenuTrailByPathActiveTrail extends MenuActiveTrail {

  /**
   * Disabled menu trail.
   */
  const MENU_TRAIL_DISABLED = 'disabled';

  /**
   * Menu trail is created using this module.
   */
  const MENU_TRAIL_PATH = 'path';

  /**
   * Menu trail is created by Drupal Core.
   */
  const MENU_TRAIL_CORE = 'core';

  /**
   * @var \Drupal\Core\Path\PathValidatorInterface
   */
   protected $pathValidator;

  /**
   * @var \Drupal\Core\Routing\RequestContext
   */
  protected $context;

  /**
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected $languageManager;

  /**
   * The configuration object.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $config;

  /**
   * MenuTrailByPathActiveTrail constructor.
   *
   * @param \Drupal\Core\Menu\MenuLinkManagerInterface $menu_link_manager
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   * @param \Drupal\Core\Lock\LockBackendInterface $lock
   * @param \Drupal\Core\Path\PathValidatorInterface $path_validator
   * @param \Drupal\Core\Routing\RequestContext $context
   * @param \Drupal\Core\Language\LanguageManagerInterface $languageManager
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   */
  public function __construct(MenuLinkManagerInterface $menu_link_manager, RouteMatchInterface $route_match, CacheBackendInterface $cache, LockBackendInterface $lock, PathValidatorInterface $path_validator, RequestContext $context, LanguageManagerInterface $languageManager, ConfigFactoryInterface $config_factory) {
    parent::__construct($menu_link_manager, $route_match, $cache, $lock);
    $this->pathValidator   = $path_validator;
    $this->context         = $context;
    $this->languageManager = $languageManager;
    $this->config = $config_factory->get('menu_trail_by_path.settings');
  }

  /**
   * {@inheritdoc}
   *
   * @see https://www.drupal.org/node/2824594
   */
  protected function getCid() {
    if (empty($this->cid)) {
      $this->cid = parent::getCid() . ":langcode:{$this->languageManager->getCurrentLanguage()->getId()}:pathinfo:{$this->context->getPathInfo()}";
    }

    return $this->cid;
  }

  /**
   * {@inheritdoc}
   */
  protected function doGetActiveTrailIds($menu_name) {
    // Parent ids; used both as key and value to ensure uniqueness.
    // We always want all the top-level links with parent == ''.
    $active_trail = ['' => ''];

    $entity = Menu::load($menu_name);
    if (!$entity) {
      return $active_trail;
    }

    // Build an active trail based on the trail source setting.
    $trail_source = $entity->getThirdPartySetting('menu_trail_by_path', 'trail_source') ?: $this->config->get('trail_source');
    if ($trail_source == static::MENU_TRAIL_CORE) {
      return parent::doGetActiveTrailIds($menu_name);
    }
    elseif ($trail_source == static::MENU_TRAIL_DISABLED) {
      return $active_trail;
    }

    // If a link in the given menu indeed matches the path, then use it to
    // complete the active trail.
    if ($active_link = $this->getActiveTrailLink($menu_name)) {
      if ($parents = $this->menuLinkManager->getParentIds($active_link->getPluginId())) {
        $active_trail = $parents + $active_trail;
      }
    }

    return $active_trail;
  }

  /**
   * Fetches the deepest, heaviest menu link which matches the deepest trail path url.
   *
   * @param string $menu_name
   *   The menu within which to find the active trail link.
   *
   * @return \Drupal\Core\Menu\MenuLinkInterface|NULL
   *   The menu link for the given menu, or NULL if there is no matching menu link.
   */
  public function getActiveTrailLink($menu_name) {
    $trail_urls = $this->getTrailUrls();

    foreach (array_reverse($trail_urls) as $trail_url) {
      $links = $this->menuLinkManager->loadLinksByRoute($trail_url->getRouteName(), $trail_url->getRouteParameters(), $menu_name);
      // Menu link manager sorts ascending by depth, weight, id. Get the
      // last one which should be the deepest menu item.
      foreach (array_reverse($links) as $link) {
        if (!$link->getUrlObject()->getOption('fragment')) {
          return $link;
        }
      }
    }

    return NULL;
  }

  /**
   * Returns a list of URL objects that represent the current path elements.
   *
   * @return \Drupal\Core\Url[]
   *   List of routed URL objects for each path element.
   */
  protected function getTrailUrls() {
    $trail_urls = $this->getCurrentPathUrls();
    if ($current_request_url = $this->getCurrentRequestUrl()) {
      $trail_urls[] = $current_request_url;
    }

    return $trail_urls;
  }

  /**
   * Returns the current request Url.
   *
   * NOTE: There is a difference between $this->routeMatch->getRouteName and $this->context->getPathInfo()
   * for now it seems more logical to prefer the latter, because that's the "real" url that visitors enter in their browser..
   *
   * @return \Drupal\Core\Url|null
   */
  protected function getCurrentRequestUrl() {
    // Special case on frontpage, allow to explicitly match on <front> menu
    // links.
    if ($this->context->getPathInfo() === '/') {
      return Url::fromRoute('<front>');
    }

    try {
      $current_pathinfo_url = $this->pathValidator->getUrlIfValidWithoutAccessCheck($this->context->getPathInfo());
    }
    catch (\InvalidArgumentException|BadRequestException $e) {
      return NULL;
    }
    if ($current_pathinfo_url && $current_pathinfo_url->isRouted()) {
      return $current_pathinfo_url;
    }
    elseif ($route_name = $this->routeMatch->getRouteName()) {
      if (!\in_array($route_name, ['system.404', 'system.403'])) {
        $route_parameters = $this->routeMatch->getRawParameters()->all();
        return Url::fromRoute($route_name, $route_parameters);

      }
    }

    return NULL;
  }

  /**
   * Returns a list of path elements based on the maximum path parts setting.
   *
   * @return string[]
   *   A list of path elements.
   */
  protected function getPathElements() {
    $path = trim($this->context->getPathInfo(), '/');
    $path_elements = explode('/', $path);

    // Limit the maximum number of path parts.
    if (is_array($path_elements) && $max_path_parts = $this->config->get('max_path_parts')) {
      return array_splice($path_elements, 0, $max_path_parts);
    }

    return $path_elements;
  }

  /**
   * @return \Drupal\Core\Url[]
   */
  protected function getCurrentPathUrls() {
    $urls = [];
    $path_elements = $this->getPathElements();

    while (count($path_elements) > 1) {
      array_pop($path_elements);
      try {
        $url = $this->pathValidator->getUrlIfValidWithoutAccessCheck('/' . implode('/', $path_elements));
      }
      catch (\InvalidArgumentException|BadRequestException $e) {
        continue;
      }
      if ($url && $url->isRouted()) {
        $urls[] = $url;
      }
    }

    return array_reverse($urls);
  }

}
