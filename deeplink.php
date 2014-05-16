<?php
/**
 * @file
 * Deep link - Allow direct access to a specific item of content under certain circumstances.
 */

define('DEEPLINK_CONTENT_TYPES', 'deeplink_content_types');
define('DEEPLINK_ALLOWED_CONTROLS', 'deeplink_allowed_controls');
define('DEEPLINK_USER_ROLES', 'deeplink_user_roles2');
define('DEEPLINK_APPROVAL_TIME', 'deeplink_approval_time');
define('DEEPLINK_APPROVAL_TIME_DEFAULT', 48);
define('DEEPLINK_DEFAULT_EMAIL_SUBJECT', 'deeplink_default_email_subject');
define('DEEPLINK_DEFAULT_EMAIL_BODY', 'deeplink_default_email_body');
define('DEEPLINK_DEFAULT_LINK', 'deeplink_default_link');
define('DEEPLINK_DEFAULT_PAGE_TITLE', 'deeplink_default_page_title');
define('DEEPLINK_DEFAULT_EMAIL_SUBJECT_CONTENT', '[site-name] Deeplink Notification');
define('DEEPLINK_DEFAULT_EMAIL_BODY_CONTENT', "Hi [user],\n\nView [title] at [deeplink-url-absolute] which expires on [deeplink-expires-medium].\n\nFrom [site-name]\n\n");
define('DEEPLINK_PERM_TEXT', 'Issue deep links using !dlink');

/**
 * Implementation of hook_permission().
 */
function deeplink_permission() {
  module_load_include('registry.inc', 'deeplink');
  return _deeplink_permission();
}

/**
 * Implementation of hook_menu().
 */
function deeplink_menu() {
  module_load_include('registry.inc', 'deeplink');
  return _deeplink_menu();
}

/**
 * Implementation of hook_theme().
 */
function deeplink_theme() {
  module_load_include('registry.inc', 'deeplink');
  return _deeplink_theme();
}

/**
 * Implementation of hook_user().
 */
function deeplink_user($op, &$edit, &$account, $category = NULL) {
  $result = NULL;
  switch ($op) {
    case 'view':
      module_load_include('admin.inc', 'deeplink');
      deeplink_view($account);
      break;
  }

  return $result;
}

/**
 * Deeplink access check
 */
function deeplink_access($deeplink) {
  if (!$deeplink) {
    // It wasn't loaded, either doesn't exist or it's expired
    drupal_not_found();
    return FALSE;
  }
  $control = controls_get('deeplinkers', $deeplink->control);
  return empty($control) ? FALSE : controls_callback(__FUNCTION__, $control, array('deeplink' => $deeplink));
}

function deeplink_title($deeplink) {
  return t('Deeplink: @title', array('@title' => $deeplink->entity->title));
}

/**
 * Implementation of hook_node_view().
 */
function deeplink_node_view($node, $view_mode = NULL, $langcode = NULL) {
  // Not a teaser, and is full page
  if ($view_mode == 'full') { // TODO: add: if (!$node->printing) in D7
    module_load_include('pages.inc', 'deeplink');
    deeplink_node_view_deeplink($node);
  }
}

function deeplink_node_view_form_validate($form, &$form_state) {
  module_load_include('pages.inc', 'deeplink');
  _deeplink_node_view_form_validate($form, $form_state);
}

function deeplink_node_view_form_submit($form, &$form_state) {
  module_load_include('pages.inc', 'deeplink');
  _deeplink_node_view_form_submit($form, $form_state);
}

/**
 * Check whether the given account (or current user) has access to this deeplink control
 */
function deeplink_access_deeplinker($deeplinker, $account = NULL) {
  return user_access(t(DEEPLINK_PERM_TEXT, array('!dlink' => $deeplinker['title'])), $account);
}

/**
 * Check whether the given type is one that we're deeplinking
 */
function deeplink_valid_type($type_name) {
  // Check this is a content type we are deeplinking to
  $deeplink_types = array_filter(variable_get(DEEPLINK_CONTENT_TYPES, array()));
  return isset($deeplink_types[$type_name]);
}

/**
 * Write deeplink record into the database
 */
function deeplink_write(&$deeplink) {
  $dlink = (array) $deeplink;
  if (!is_string($dlink['data'])) {
    $dlink['data'] = serialize($dlink['data']);
  }
  // insert or update a deeplink record
  if (!isset($dlink['dlid'])) {
    // Create the new ID (replace zeroes with 'g')
    $deeplink->dlid = $dlink['dlid'] = str_replace('0', 'g', md5(serialize($dlink)));
    // extract the array contents as variables for ease of processing
    extract($dlink);
    // and insert
    $res = db_insert('deeplinks')
      ->fields(array(
            'dlid' => $dlid,
            'control' => $control,
            'entity_type' => $entity_type,
            'bundle' => $bundle,
            'entity_id' => $entity_id,
            'expires' => $expires,
            'data' => $data,
            ))
      ->execute();
  }
  else {
    // should never need to update, but for completeness
    // extract the array contents as variables for ease of processing
    extract($dlink);
    //and update
    $res = db_insert('deeplinks')
      ->fields(array(
            'control' => $control,
            'entity_type' => $entity_type,
            'bundle' => $bundle,
            'entity_id' => $entity_id,
            'expires' => $expires,
            'data' => $data,
            ))
      ->condition('dlid', $dlid, '=')
      ->execute();
  }
}

/**
 * Load deeplink record
 * @param string $dlid Deeplink ID
 */
function deeplink_load($dlid) {
  // Rather than mess about with hook_cron(), remove all expired deeplinks here
  $res = db_delete('deeplinks')
    ->condition('expires', time(), '<')
    ->condition('expires', 0, '<>')
    ->execute();

  // Fetch the deeplink
  if ($deeplink = (object)db_select('deeplinks', 'd')->fields('d')->condition('dlid', $dlid)->execute()->fetchAssoc()) {
    // If we have a live one may as well actually fetch the damn thing
    /*$node = node_load($deeplink->entity_id);
    $node->deeplink = clone $deeplink; // clone to avoid circular referencing
    $deeplink->entity_type = 'node';
    $deeplink->entity = $node;
    $deeplink->data = unserialize((string)$deeplink->data);*/
    
    $file = file_load($deeplink->entity_id);
    $file->deeplink = clone $deeplink; // clone to avoid circular referencing
    $deeplink->entity_type = 'file';
    $deeplink->entity = $file;
    $deeplink->data = unserialize((string)$deeplink->data);
  }
  return $deeplink;
}

/**
 * Delete deeplink record
 * @param string $dlid Deeplink ID
 */
function deeplink_delete($dlid) {
  return db_delete('deeplinks')
    ->condition('dlid', $dlid)
    ->execute();
}

function deeplink_read_by_entity($bundle, $entity_id, $entity_type = 'node') {
  return db_select('deeplinks', 'd')
    ->fields('d')
    ->condition('entity_type', $entity_type)
    ->condition('bundle', $bundle)
    ->condition('entity_id', $entity_id)
    ->execute()
    ->fetchAssoc();
}

/**
 * Return deeplink URI
 * @param object $node Provide node object to check if URI is overriden by controls
 * @param bool $all If TRUE, returns all deeplink URIs defined by controls
 */
function deeplink_get_base_uri($node = NULL, $all = FALSE) {
  $uris = controls_get('uri');
  if (!$all) {
    if (is_object($node) && array_key_exists($node->type, $uris)) {
      $uri = $uris[$node->type]['uri'];
    } else {
      $uri = variable_get(DEEPLINK_DEFAULT_LINK, 'deeplink');
    }
  } else {
    $uri = $uris;
  }
  return $uri;
}

/**
 * Function to generate deeplink link for specified node
 */
function deeplink_generate_node_deeplink($node, $control_type = 'byuser', $data = array(0 => NULL)) {
  $hours = variable_get(DEEPLINK_APPROVAL_TIME, DEEPLINK_APPROVAL_TIME_DEFAULT);
  $expires = ($hours == 0) ? 0 : (gmmktime() + (3600 * $hours));
  $deeplink = (object) array(
    'control' => $control_type,
    'entity_type' => 'node',
    'bundle' => $node->type,
    'entity_id' => $node->nid,
    'expires' => $expires,
    'data' => $data,
  );
  try {
    deeplink_write($deeplink);
  } catch (Exception $e) {
    watchdog('Deeplink', $e->getMessage(), WATCHDOG_ERROR);
  }
  $deeplink = deeplink_read_by_entity($node->type, $node->nid);
  $url = deeplink_get_base_uri($node) . '/' . $deeplink['dlid'];
  return $url;
}

/**
 * Function to generate deeplink link for specified file
 */
function deeplink_generate_file_deeplink($fid, $control_type = 'byuser', $data = array(0 => NULL)) {
  $hours = variable_get(DEEPLINK_APPROVAL_TIME, DEEPLINK_APPROVAL_TIME_DEFAULT);
  //$expires = ($hours == 0) ? 0 : (gmmktime() + (3600 * $hours));
  $expires = ($hours == 0) ? 0 : (time() + (3600 * $hours));
  $deeplink = (object) array(
    'control' => $control_type,
    'entity_type' => 'file',
    'bundle' => 'logo',
    'entity_id' => $fid,
    'expires' => $expires,
    'data' => $data,
  );
  try {
    deeplink_write($deeplink);
  } catch (Exception $e) {
    watchdog('Deeplink', $e->getMessage(), WATCHDOG_ERROR);
  }
  $deeplink = deeplink_read_by_entity('logo', $fid,'file');
  $url = deeplink_get_base_uri() . '/' . $deeplink['dlid'];
  //$url = 'deeplink/' . $deeplink['dlid'];
  return $url;
}