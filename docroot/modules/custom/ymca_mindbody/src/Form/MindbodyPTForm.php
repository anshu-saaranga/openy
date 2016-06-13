<?php

namespace Drupal\ymca_mindbody\Form;

use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Url;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\FormBase;
use Drupal\mindbody_cache_proxy\MindbodyCacheProxyInterface;
use Drupal\Core\Datetime\DrupalDateTime;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\State\StateInterface;

/**
 * Provides the Personal Training Form.
 *
 * @ingroup ymca_mindbody
 */
class MindbodyPTForm extends FormBase {

  /**
   * Mindbody Proxy.
   *
   * @var MindbodyCacheProxyInterface
   */
  protected $proxy;

  /**
   * Credentials.
   *
   * @var ImmutableConfig
   */
  protected $credentials;

  /**
   * State.
   *
   * @var StateInterface
   */
  protected $state;

  /**
   * MindbodyPTForm constructor.
   *
   * @param StateInterface $state
   *   State.
   * @param MindbodyCacheProxyInterface $cache_proxy
   *   Mindbody cache proxy.
   */
  public function __construct(StateInterface $state, MindbodyCacheProxyInterface $cache_proxy) {
    $this->proxy = $cache_proxy;
    $this->credentials = $this->config('mindbody.settings');
    $this->state = $state;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static($container->get('state'), $container->get('mindbody_cache_proxy.client'));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'mindbody_pt';
  }

  /**
   * Provides form's headers.
   */
  protected function getElementHeaderMarkup($type, $text) {
    switch ($type) {
      case 'location':
        $icon = 'location2';
        $id = 'location-wrapper';
        break;

      case 'program':
        $icon = 'training';
        $id = 'program-wrapper';
        break;

      case 'type':
        $icon = 'clock';
        $id = 'session-type-wrapper';
        break;

      case 'trainer':
        $icon = 'user';
        $id = 'trainer-wrapper';
        break;
    }
    $markup = '<div class="header-row"><div class="container">';
    $markup .= '<span class="icon icon-' . $icon . '"></span>';
    $markup .= '<span class="choice">' . $text . '</span>';
    $markup .= '<a href="#' . $id . '" class="change"><span class="icon icon-cog"></span>' . $this->t('Change') . '</a>';
    $markup .= '</div></div>';

    return $markup;
  }

  /**
   * Provides time options.
   */
  protected function getTimeOptions() {
    $time_options = [
      '12 am', '1 am', '2 am', '3 am', '4 am', '5 am', '6 am', '7 am', '8 am', '9 am', '10 am', '11 am',
      '12 pm', '1 pm', '2 pm', '3 pm', '4 pm', '5 pm', '6 pm', '7 pm', '8 pm', '9 pm', '10 pm', '11 pm', '12 am',
    ];

    return $time_options;
  }

  /**
   * Provides markup for disabled form.
   */
  protected function getDisabledMarkup() {
    $markup = '';
    $block_id = $this->config('ymca_mindbody.block.settings')->get('disabled_form_block_id');
    $block = \Drupal::entityTypeManager()->getStorage('block_content')->load($block_id);
    if (!is_null($block)) {
      $view_builder = \Drupal::entityTypeManager()->getViewBuilder('block_content');
      $markup .= '<div class="container disabled-form">';
      $markup .= render($view_builder->view($block));
      $markup .= '</div>';
    }
    return $markup;
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $values = $form_state->getValues();

    if ($trigger_element = $form_state->getTriggeringElement()) {
      switch ($trigger_element['#name']) {
        case 'mb_location':
          unset($values['mb_program']);
          unset($values['mb_session_type']);
          unset($values['mb_trainer']);
          $values['step'] = 2;
          break;

        case 'mb_program':
          unset($values['mb_session_type']);
          unset($values['mb_trainer']);
          $values['step'] = 3;
          break;

        case 'mb_session_type':
          unset($values['mb_trainer']);
          $values['step'] = 4;
          break;

        case 'ok':
          $values['step'] = 5;
          break;
      }
    }

    if (!isset($values['step'])) {
      $values['step'] = 1;
    }

    $form['step'] = [
      '#type' => 'hidden',
      '#value' => $values['step'],
    ];

    $form['#prefix'] = '<div id="mindbody-pt-form-wrapper" class="content step-' . $values['step'] . '">';
    $form['#suffix'] = '</div>';

    // Disable form if we exceed 1000 calls to MindBody API.
    $mindbody_proxy_state = $this->state->get('mindbody_cache_proxy');
    if (isset($mindbody_proxy_state->miss) && $mindbody_proxy_state->miss >= 1000) {
      $form['disable'] = [
        "#markup" => $this->getDisabledMarkup(),
      ];
      return $form;
    }

    $locations = $this->proxy->call('SiteService', 'GetLocations');

    $location_options = [];
    foreach ($locations->GetLocationsResult->Locations->Location as $location) {
      if ($location->HasClasses != TRUE) {
        continue;
      }
      $location_options[$location->ID] = $location->Name;
    }
    $form['mb_location'] = array(
      '#type' => 'radios',
      '#title' => $this->t('Select Location'),
      '#options' => $location_options,
      '#default_value' => isset($values['mb_location']) ? $values['mb_location'] : '',
      '#prefix' => '<div id="location-wrapper" class="row"><div class="container">',
      '#suffix' => '</div></div>',
      '#description' => $this->t('You can only select 1 branch per search'),
      '#weight' => 2,
      '#ajax' => array(
        'callback' => array($this, 'rebuildAjaxCallback'),
        'wrapper' => 'mindbody-pt-form-wrapper',
        'event' => 'change',
        'method' => 'replace',
        'effect' => 'fade',
        'progress' => array(
          'type' => 'throbber',
        ),
      ),
    );

    if ($values['step'] >= 2) {
      $form['mb_location_header'] = array(
        '#markup' => $this->getElementHeaderMarkup('location', $location_options[$values['mb_location']]),
        '#weight' => 1,
      );
      $programs = $this->proxy->call('SiteService', 'GetPrograms', ['OnlineOnly' => FALSE, 'ScheduleType' => 'Appointment']);
      $program_options = [];
      foreach ($programs->GetProgramsResult->Programs->Program as $program) {
        $program_options[$program->ID] = $program->Name;
      }
      $form['mb_program'] = array(
        '#type' => 'radios',
        '#title' => $this->t('Appointment Type'),
        '#options' => $program_options,
        '#default_value' => isset($values['mb_program']) ? $values['mb_program'] : '',
        '#prefix' => '<div id="program-wrapper" class="row"><div class="container">',
        '#suffix' => '</div></div>',
        '#weight' => 4,
        '#ajax' => array(
          'callback' => array($this, 'rebuildAjaxCallback'),
          'wrapper' => 'mindbody-pt-form-wrapper',
          'method' => 'replace',
          'event' => 'change',
          'effect' => 'fade',
          'progress' => array(
            'type' => 'throbber',
          ),
        ),
      );
    }

    if ($values['step'] >= 3) {
      $form['mb_program_header'] = array(
        '#markup' => $this->getElementHeaderMarkup('program', $program_options[$values['mb_program']]),
        '#weight' => 3,
      );
      $session_types = $this->proxy->call('SiteService', 'GetSessionTypes', ['OnlineOnly' => FALSE, 'ProgramIDs' => [$values['mb_program']]]);
      $session_type_options = [];
      foreach ($session_types->GetSessionTypesResult->SessionTypes->SessionType as $type) {
        $session_type_options[$type->ID] = $type->Name;
      }
      $form['mb_session_type'] = array(
        '#type' => 'radios',
        '#title' => $this->t('Training type'),
        '#options' => $session_type_options,
        '#default_value' => isset($values['mb_session_type']) ? $values['mb_session_type'] : '',
        '#prefix' => '<div id="session-type-wrapper" class="row"><div class="container">',
        '#suffix' => '</div></div>',
        '#weight' => 6,
        '#ajax' => array(
          'callback' => array($this, 'rebuildAjaxCallback'),
          'wrapper' => 'mindbody-pt-form-wrapper',
          'event' => 'change',
          'effect' => 'fade',
          'progress' => array(
            'type' => 'throbber',
          ),
        ),
      );
    }

    if ($values['step'] >= 4) {
      $form['mb_session_type_header'] = array(
        '#markup' => $this->getElementHeaderMarkup('type', $session_type_options[$values['mb_session_type']]),
        '#weight' => 5,
      );
      /*
       * NOTE: MINDBODY API doesn't support filtering staff by location without specific date and time.
       * That's why we see all trainers, even courts.
       * see screenshot https://goo.gl/I9uNY2
       * see API Docs https://developers.mindbodyonline.com/Develop/StaffService
       */
      $booking_params = array(
        'UserCredentials' => array(
          'Username' => $this->credentials->get('user_name'),
          'Password' => $this->credentials->get('user_password'),
          'SiteIDs' => [$this->credentials->get('site_id')],
        ),
        'SessionTypeIDs' => [$values['mb_session_type']],
        'LocationIDs' => [$values['mb_location']],
      );
      $bookable = $this->proxy->call('AppointmentService', 'GetBookableItems', $booking_params);

      $staff_list = array();
      foreach ($bookable->GetBookableItemsResult->ScheduleItems->ScheduleItem as $bookable_item) {
        $staff_list[$bookable_item->Staff->ID] = $bookable_item->Staff;
      }
      $trainer_options = array(
        'all' => $this->t('All'),
      );
      foreach ($staff_list as $staff) {
        $trainer_options[$staff->ID] = $staff->Name;
      }

      $form['mb_trainer'] = array(
        '#type' => 'select',
        '#title' => $this->t('Trainer'),
        '#options' => $trainer_options,
        '#default_value' => isset($values['mb_trainer']) ? $values['mb_trainer'] : 'all',
        '#prefix' => '<div id="trainer-wrapper" class="row"><div class="container"><div class="col-sm-4">',
        '#suffix' => '</div></div></div>',
        '#weight' => 8,
      );

      $form['actions']['#weight'] = 20;
      $form['actions']['#prefix'] = '<div id="actions-wrapper" class="row"><div class="container"><div class="col-sm-12">';
      $form['actions']['#suffix'] = '</div></div></div>';

      $timezone = drupal_get_user_timezone();
      // Initially srart date defined as today.
      $start_date = DrupalDateTime::createFromTimestamp(REQUEST_TIME, $timezone);
      $start_date->setTime(0, 0, 0);
      // Initially end date defined as +5 days after start date.
      $end_date = DrupalDateTime::createFromTimestamp(REQUEST_TIME + 432000, $timezone);
      $end_date->setTime(0, 0, 0);
      if (!empty($values['mb_start_date']['date'])) {
        $date = DrupalDateTime::createFromFormat('n/d/y', $values['mb_start_date']['date'], $timezone);
        $date->setTime(0, 0, 0);
        $start_date = $date;
      }
      if (!empty($values['mb_end_date']['date'])) {
        $date = DrupalDateTime::createFromFormat('n/d/y', $values['mb_end_date']['date'], $timezone);
        $date->setTime(0, 0, 0);
        $end_date = $date;
      }
      $form['mb_date'] = [
        '#type' => 'fieldset',
        '#prefix' => '<div id="when-wrapper" class="row"><div class="container"><div class="col-sm-12">',
        '#suffix' => '</div></div></div>',
        '#weight' => 9,
      ];
      $form['mb_date']['mb_start_time'] = [
        '#type' => 'select',
        '#title' => $this->t('Time range'),
        '#options' => $this->getTimeOptions(),
        '#default_value' => isset($values['mb_start_time']) ? $values['mb_start_time'] : '',
        '#suffix' => '<span class="dash">—</span>',
        '#default_value' => 6,
        '#weight' => 9,
      ];
      $form['mb_date']['mb_end_time'] = [
        '#type' => 'select',
        '#title' => '',
        '#options' => $this->getTimeOptions(),
        '#default_value' => isset($values['mb_end_time']) ? $values['mb_end_time'] : '',
        '#default_value' => 9,
        '#weight' => 9,
      ];
      $form['mb_date']['mb_start_date'] = [
        '#type' => 'datetime',
        '#date_date_format' => 'n/d/y',
        '#title' => $this->t('Date range'),
        '#suffix' => '<span class="dash">—</span>',
        '#default_value' => $start_date,
        '#date_time_element' => 'none',
        '#date_date_element' => 'text',
        '#weight' => 9,
      ];
      $form['mb_date']['mb_end_date'] = [
        '#type' => 'datetime',
        '#date_date_format' => 'n/d/y',
        '#title' => '',
        '#default_value' => $end_date,
        '#date_time_element' => 'none',
        '#date_date_element' => 'text',
        '#weight' => 9,
      ];

      $form['actions']['submit'] = array(
        '#type' => 'submit',
        '#value' => $this->t('Search'),
      );
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function rebuildAjaxCallback(array &$form, FormStateInterface $form_state) {
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getSearchResults($values) {
    if (isset($values['location']) && isset($values['program']) && isset($values['session_type']) && isset($values['trainer']) && isset($values['start_date']) && isset($values['end_date'])) {
      $booking_params = [
        'UserCredentials' => [
          'Username' => $this->credentials->get('user_name'),
          'Password' => $this->credentials->get('user_password'),
          'SiteIDs' => [$this->credentials->get('site_id')],
        ],
        'SessionTypeIDs' => [$values['session_type']],
        'LocationIDs' => [$values['location']],
      ];

      if (!empty($values['trainer']) && $values['trainer'] != 'all') {
        $booking_params['StaffIDs'] = array($values['trainer']);
      }
      $booking_params['StartDate'] = date('Y-m-d', strtotime($values['start_date']));
      $booking_params['EndDate'] = date('Y-m-d', strtotime($values['end_date']));

      $bookable = $this->proxy->call('AppointmentService', 'GetBookableItems', $booking_params);

      $time_options = $this->getTimeOptions();
      $start_time = $time_options[$values['start_time']];
      $end_time = $time_options[$values['end_time']];

      foreach ($time_options as $key => $option) {
        if ($option == $start_time) {
          $start_index = $key;
        }
        if ($option == $end_time) {
          $end_index = $key;
        }
      }
      $time_range = range($start_index, $end_index);

      $days = [];
      // Group results by date and trainer.
      foreach ($bookable->GetBookableItemsResult->ScheduleItems->ScheduleItem as $bookable_item) {
        // Additionally filter results by time.
        $start_time = date('G', strtotime($bookable_item->StartDateTime));
        $end_time = date('G', strtotime($bookable_item->EndDateTime));
        if (in_array($start_time, $time_range) && in_array($end_time, $time_range)) {
          $group_date = date('F d, Y', strtotime($bookable_item->StartDateTime));
          $days[$group_date]['weekday'] = date('l', strtotime($bookable_item->StartDateTime));
          $days[$group_date]['trainers'][$bookable_item->Staff->Name][] = [
            'is_available' => TRUE,
            'slot' => date('h:i a', strtotime($bookable_item->StartDateTime)) . ' - ' . date('h:i a', strtotime($bookable_item->EndDateTime)),
            // To Do: Add bookable link.
            'href' => '#',
          ];
        }
      }

      $programs = $this->proxy->call('SiteService', 'GetPrograms', ['OnlineOnly' => FALSE, 'ScheduleType' => 'Appointment']);
      foreach ($programs->GetProgramsResult->Programs->Program as $program) {
        if ($program->ID == $values['program']) {
          $program_name = $program->Name;
        }
      }

      $trainer = $bookable_item->Staff->Name;
      if ($values['trainer'] == 'all') {
        $trainer = $this->t('all trainers');
      }

      $time_options = $this->getTimeOptions();
      $start_time = $time_options[$values['start_time']];
      $end_time = $time_options[$values['end_time']];
      $start_date = date('n/d/Y', strtotime($values['start_date']));
      $end_date = date('n/d/Y', strtotime($values['end_date']));
      $datetime = '<div><span class="icon icon-calendar"></span><span>' . $this->t('Time:') . '</span> ' . $start_time . ' - ' . $end_time . '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</div><div><span>' . $this->t('Date:') . '</span> ' . $start_date . ' - ' . $end_date . '</div>';

      $search_results = [
        '#theme' => 'mindbody_results_content',
        '#location' => $bookable_item->Location->Name,
        '#program' => $program_name,
        '#session_type' => $bookable_item->SessionType->Name,
        '#trainer' => $trainer,
        '#datetime' => $datetime,
        '#back_link' => Url::fromRoute('ymca_mindbody.pt'),
        '#base_path' => base_path(),
        '#days' => $days,
      ];

      return $search_results;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();
    if (isset($values['mb_start_time']) && isset($values['mb_end_time'])  && $values['mb_start_time'] >= $values['mb_end_time']) {
      $form_state->setErrorByName('mb_start_time', $this->t('Please check time range.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getUserInput();
    if (!empty($values['mb_location']) &&
      !empty($values['mb_program']) &&
      !empty($values['mb_session_type']) &&
      !empty($values['mb_trainer']) &&
      !empty($values['mb_start_time']) &&
      !empty($values['mb_end_time']) &&
      !empty($values['mb_start_date']) &&
      !empty($values['mb_end_date'])) {
      $params = [
        'location'     => $values['mb_location'],
        'program'      => $values['mb_program'],
        'session_type' => $values['mb_session_type'],
        'trainer'      => $values['mb_trainer'],
        'start_time'   => $values['mb_start_time'],
        'end_time'     => $values['mb_end_time'],
        'start_date'   => $values['mb_start_date']['date'],
        'end_date'     => $values['mb_end_date']['date'],
      ];
      $form_state->setRedirect(
        'ymca_mindbody.pt.results',
        [],
        ['query' => $params]
      );
    }
  }

}
