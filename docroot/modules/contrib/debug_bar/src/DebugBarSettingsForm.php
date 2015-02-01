<?php

/**
 * @file
 * Contains \Drupal\debug_bar\Form\DebugBarSettingForm.
 */

namespace Drupal\debug_bar;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\ConfigFormBase;

/**
 * Builds and process a form for editing a single entity field.
 */
class DebugBarSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'debug_bar_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {

    $settings = $this->config('debug_bar.settings');

    $form['float'] = [
      '#type' => 'checkbox',
      '#title' => t('Float'),
      '#description' => t('Make bar draggable'),
      '#default_value' => $settings->get('float'),
    ];

    $form['position'] = [
      '#type' => 'radios',
      '#title' => t('Position'),
      '#options' => [
        'top_left' => t('Top left'),
        'top_right' => t('Top right'),
        'bottom_left' => t('Bottom left'),
        'bottom_right' => t('Bottom right'),
      ],
      '#default_value' => $settings->get('position'),
      '#states' => [
        'visible' => [
          'input[name="float"]' => ['checked' => FALSE],
        ],
      ],
    ];

    $form['appearance'] = [
      '#type' => 'radios',
      '#title' => t('Appearance'),
      '#options' => [
        'both' => t('Icons and text'),
        'icons' => t('Icons only'),
        'text' => t('Text only'),
      ],
      '#default_value' => $settings->get('appearance'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('debug_bar.settings')
      ->set('float', $form_state->getValue('float'))
      ->set('position', $form_state->getValue('position'))
      ->set('appearance', $form_state->getValue('appearance'))
      ->save();
    parent::submitForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['debug_bar.settings'];
  }

}
