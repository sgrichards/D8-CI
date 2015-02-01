<?php

/**
 * @file
 * Contains \Drupal\debug_bar\EventSubscriber\DebugBarEventSubscriber.
 */

namespace Drupal\debug_bar;

use Drupal\Core\Session\AccountInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\FilterResponseEvent;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Url;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\CronInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Datetime\DateFormatter;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Component\Utility\Timer;
use Drupal\Component\Utility\SafeMarkup;
use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\SortArray;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Debug bar event subscriber.
 */
class DebugBarEventSubscriber implements EventSubscriberInterface {

  /**
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected  $currentUser;

  /**
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * @var array
   */
  protected $settings;

  /**
   * @var \Drupal\Core\Database\Log
   */
  protected $databaseLogger;

  /**
   * @var \Drupal\Core\CronInterface
   */
  protected $cron;

  /**
   * @var  \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * @var  \Drupal\Core\Datetime\DateFormatter
   */
  protected $dateFormatter;

  /**
   * @var  \Drupal\Core\Datetime\DateFormatter
   */
  protected $csrfTokenGenerator;

  /**
   * Constructs event subscriber.
   *
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   Current logged in user.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   Module handler.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   Configuration object factory.
   * @param \Drupal\Core\Database\Connection $db_connection
   *   Dabase connection
   * @param \Drupal\Core\State\StateInterface $state
   *   State system.
   * @param \Drupal\Core\CronInterface $cron
   *   Cron service.
   * @param \Drupal\Core\Datetime\DateFormatter $date_formatter
   *   Date formatter.
   * @param \Drupal\Core\Access\CsrfTokenGenerator $csrf_token_generator
   *   CSRF token generator.
   */
  public function __construct(
    AccountInterface $current_user,
    ModuleHandlerInterface $module_handler,
    ConfigFactoryInterface $config_factory,
    Connection $db_connection,
    StateInterface $state,
    CronInterface $cron,
    DateFormatter $date_formatter,
    CsrfTokenGenerator $csrf_token_generator
  ) {

    $this->currentUser = $current_user;
    $this->isAdmin = $this->currentUser->hasPermission('administer site configuration');
    $this->moduleHandler = $module_handler;
    $this->settings = $config_factory->get('debug_bar.settings');
    $this->databaseLogger = $db_connection->getLogger();
    $this->cron = $cron;
    $this->state = $state;
    $this->dateFormatter = $date_formatter;
    $this->csrfTokenGenerator = $csrf_token_generator;

  }

  /**
   * Kernel request event handler.
   *
   * @param \Symfony\Component\HttpKernel\Event\GetResponseEvent $event
   *   Response event.
   */
  public function onKernelRequest(GetResponseEvent $event) {

    if ($event->getRequest()->get('debug-bar-run-cron') && $this->isAdmin) {
      $this->cron->run();
      drupal_set_message(t('Cron ran successfully.'));
      // Do we need this redirect?
      $event->setResponse(new RedirectResponse(Url::fromRoute('<current>')->toString()));
    }

    if ($event->getRequest()->get('debug-bar-flush-cache') && $this->isAdmin) {
      drupal_flush_all_caches();
      drupal_set_message(t('Caches cleared.'));
      $event->setResponse(new RedirectResponse(Url::fromRoute('<current>')->toString()));
    }

  }

  /**
   * Kernel response event handler.
   *
   * @param \Symfony\Component\HttpKernel\Event\FilterResponseEvent $event
   *   Response event.
   */
  public function onKernelResponse(FilterResponseEvent $event) {

    // Do not capture redirects or modify XML HTTP Requests.
    if ($event->getRequest()->isXmlHttpRequest()) {
      return;
    }

    if (!$this->currentUser->hasPermission('view debug bar')) {
      return;
    }

    // Empty settings mean the module is being uninstalled.
    if (!$settings = $this->settings->get()) {
      return;
    }

    $this->injectBar($event->getResponse(), $settings);

  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      KernelEvents::REQUEST => ['onKernelRequest'],
      KernelEvents::RESPONSE => ['onKernelResponse'],
    ];
  }

  /**
   * Injects debug bar into page body.
   *
   * @param \Symfony\Component\HttpFoundation\Response $response
   *   Http response.
   */
  protected function injectBar(Response $response, $settings) {
    $content = $response->getContent();

    $pos = mb_strripos($content, '</body>');
    if ($pos === FALSE) {
      return;
    }

    $items = $this->getItems();

    // Add close button.
    $items['debug_bar-link-hide'] = array(
      'title' => '',
      'url' => Url::fromRoute('<front>'),
      'weight' => $this->settings->get('float') || strpos($this->settings->get('position'), 'left') ? -1000 : 1000,
      'access' => TRUE,
    );

    $this->moduleHandler->alter('debug_bar_items', $items);
    uasort($items, [
        'Drupal\Component\Utility\SortArray',
        'sortByWeightElement'
      ]);

    foreach ($items as $id => $link) {

      if ($link['access']) {

        if ($settings['appearance'] == 'icons') {
          $link['title'] = '';
        }

        if ($settings['appearance'] != 'text' && isset($link['icon_path'])) {
          $icon = [
            '#theme' => 'image',
            '#uri' => $link['icon_path'],
            '#attributes' => ['class' => 'debug-bar-link-icon'],
          ];
          $link['title'] = render($icon) . $link['title'];
        }

        // Avoid double escaping.
        $items[$id]['title'] = empty($link['url']) ? SafeMarkup::set($link['title']) : $link['title'];
        $items[$id]['html'] = TRUE;
        $items[$id]['attributes']['class'][] = 'debug-bar-link';
      }
      else {
        unset($items[$id]);
      }

    }

    if ($settings['float']) {
      $classes[] = 'debug-bar-float';
    }
    else {
      $classes[] = 'debug-bar-' . Html::cleanCssIdentifier($this->settings->get('position'));
    }

    if (!empty($_COOKIE['debug_bar_hidden'])) {
      $classes[] = 'debug-bar-hidden';
    }

    $debug_bar = [
      '#type' => 'container',
      '#attributes' => ['id' => 'debug-bar-wrapper'],
      'links' => [
        '#theme' => 'links',
        '#attributes' => [
          'id' => 'debug-bar',
          'class' => implode(' ', $classes)
        ],
        '#links' => $items,
      ]
    ];

    $content = mb_substr($content, 0, $pos) . render($debug_bar) . mb_substr($content, $pos);
    $response->setContent($content);

  }

  /**
   * Returns default list of elements for debug bar.
   */
  protected function getItems() {

    $images_path = base_path() . drupal_get_path('module', 'debug_bar') . '/images';

    $items['debug_bar_item_home'] = [
      'title' => t('Home'),
      'url' => Url::fromRoute('<front>'),
      'icon_path' => $images_path . '/home.png',
      'attributes' => ['title' => t('Front page')],
      'weight' => 10,
      'access' => TRUE,
    ];

    $items['debug_bar_item_status_report'] = [
      'title' => \Drupal::VERSION,
      'url' => Url::fromRoute('system.status'),
      'icon_path' => $images_path . '/druplicon.png',
      'attributes' => ['title' => t('View status report')],
      'weight' => 20,
      'access' => $this->currentUser->hasPermission('access site reports'),
    ];

    $items['debug_bar_item_execution_time'] = [
      'title' => round(Timer::read('page'), 1) . ' ms',
      'icon_path' => $images_path . '/time.png',
      'attributes' => ['title' => t('Execution time')],
      'weight' => 30,
      'access' => TRUE,
    ];

    $items['debug_bar_item_memory_usage'] = [
      'title' => round(memory_get_peak_usage(TRUE) / 1024 / 1024, 2) . ' MB',
      'icon_path' => $images_path . '/memory.png',
      'attributes' => ['title' => t('Peak memory usage')],
      'weight' => 40,
      'access' => TRUE,
    ];

    $items['debug_bar_item_db_queries'] = [
      'title' => count($this->databaseLogger->get('debug_bar')),
      'icon_path' => $images_path . '/db-queries.png',
      'attributes' => ['title' => t('DB queries')],
      'weight' => 50,
      'access' => TRUE,
    ];

    list($php_version) = explode('-', PHP_VERSION);
    $items['debug_bar_item_php'] = [
      'title' => $php_version,
      'url' => Url::fromRoute('system.php'),
      'icon_path' => $images_path . '/php.png',
      'attributes' => ['title' => t("View information about PHP's configuration")],
      'weight' => 60,
      'access' => $this->isAdmin,
    ];

    $cron_last = $this->state->get('system.cron_last');
    $items['debug_bar_item_cron'] = [
      'title' => t('Run cron'),
      'url' => Url::fromRoute('<current>'),
      'icon_path' => $images_path . '/cron.png',
      'attributes' => [
        'title' => t(
          'Last run !time ago',
          ['!time' => $this->dateFormatter->formatInterval(REQUEST_TIME - $cron_last)]
        ),
      ],
      'query' => [
        'debug-bar-run-cron' => '1',
        'token' => $this->csrfTokenGenerator->get('debug-bar-run-cron'),
      ],
      'weight' => 70,
      'access' => $this->isAdmin,
    ];

    $git_branch = $this->getGitBranch();
    $items['debug_bar_item_git'] = [
      'title' => $git_branch,
      'icon_path' => $images_path . '/git.png',
      'attributes' => ['title' => t('Current branch')],
      'weight' => 80,
      'access' => $git_branch,
    ];

    $items['debug_bar_item_watchdog'] = [
      'title' => t('Log'),
      'url' => Url::fromRoute('dblog.overview'),
      'icon_path' => $images_path . '/log.png',
      'attributes' => ['title' => t('Recent log messages')],
      'weight' => 90,
      'access' => $this->currentUser->hasPermission('access site reports'),
    ];

    $items['debug_bar_item_cache'] = array(
      'title' => t('Cache'),
      'url' => Url::fromRoute('<current>'),
      'icon_path' => $images_path . '/cache.png',
      'attributes' => array('title' => t('Clear all caches')),
      'query' => array(
        'debug-bar-flush-cache' => '1',
        'token' => $this->csrfTokenGenerator->get('debug-bar-flush-cache'),
      ),
      'weight' => 100,
      'access' => $this->isAdmin,
    );

    $items['debug_bar_item_login'] = array(
      'title' => t('Log in'),
      'url' => Url::fromRoute('user.login'),
      'icon_path' => $images_path . '/login.png',
      'attributes' => array('title' => t('Log in')),
      'weight' => 110,
      'access' => $this->currentUser->isAnonymous(),
    );

    $items['debug_bar_item_user'] = array(
      'title' => $this->currentUser->getUsername(),
      'url' => Url::fromRoute('entity.user.canonical', ['user' => $this->currentUser->id()]),
      'icon_path' => $images_path . '/user.png',
      'attributes' => array('title' => t('View profile')),
      'weight' => 120,
      'access' => $this->currentUser->isAuthenticated(),
    );

    $items['debug_bar_item_logout'] = array(
      'title' => t('Log out'),
      'url' => Url::fromRoute('user.logout'),
      'icon_path' => $images_path . '/logout.png',
      'attributes' => array('title' => t('Log out')),
      'weight' => 130,
      'access' => $this->currentUser->isAuthenticated(),
    );

    return $items;
  }

  /**
   * Extract the current checked out branch.
   */
  protected function getGitBranch($file = '.git/HEAD') {
    is_readable($file) && $data = file_get_contents($file);
    return isset($data) && ($data = explode('/', $data)) ? end($data) : NULL;
  }

}
