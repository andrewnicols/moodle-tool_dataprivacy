<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.
/**
 * Class containing the external API functions functions for the Data Privacy tool.
 *
 * @package    tool_dataprivacy
 * @copyright  2018 Jun Pataleta
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace tool_dataprivacy;
defined('MOODLE_INTERNAL') || die();

require_once("$CFG->libdir/externallib.php");

use coding_exception;
use context_system;
use context_user;
use core\invalid_persistent_exception;
use core_user;
use dml_exception;
use external_api;
use external_description;
use external_function_parameters;
use external_multiple_structure;
use external_single_structure;
use external_value;
use external_warnings;
use invalid_parameter_exception;
use moodle_exception;
use required_capability_exception;
use restricted_context_exception;
use tool_dataprivacy\external\data_request_exporter;
use tool_dataprivacy\external\category_exporter;
use tool_dataprivacy\external\purpose_exporter;
use tool_dataprivacy\output\data_registry_page;

/**
 * Class external.
 *
 * The external API for the Data Privacy tool.
 *
 * @copyright  2017 Jun Pataleta
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class external extends external_api {

    /**
     * Parameter description for cancel_data_request().
     *
     * @return external_function_parameters
     */
    public static function cancel_data_request_parameters() {
        return new external_function_parameters([
            'requestid' => new external_value(PARAM_INT, 'The request ID', VALUE_REQUIRED)
        ]);
    }

    /**
     * Cancel a data request.
     *
     * @param int $requestid The request ID.
     * @return array
     * @throws invalid_persistent_exception
     * @throws coding_exception
     * @throws invalid_parameter_exception
     * @throws restricted_context_exception
     */
    public static function cancel_data_request($requestid) {
        global $USER;

        $warnings = [];
        $params = external_api::validate_parameters(self::cancel_data_request_parameters(), [
            'requestid' => $requestid
        ]);
        $requestid = $params['requestid'];

        // Validate context.
        $context = context_user::instance($USER->id);
        self::validate_context($context);

        // Ensure the request exists.
        $select = 'id = :id AND requestedby = :requestedby';
        $params = ['id' => $requestid, 'requestedby' => $USER->id];
        $requestexists = data_request::record_exists_select($select, $params);

        $result = false;
        if ($requestexists) {
            // TODO: Do we want a request to be non-cancellable past a certain point? E.g. When it's already approved/processing.
            $result = api::update_request_status($requestid, api::DATAREQUEST_STATUS_CANCELLED);
        } else {
            $warnings[] = [
                'item' => $requestid,
                'warningcode' => 'errorrequestnotfound',
                'message' => get_string('errorrequestnotfound', 'tool_dataprivacy')
            ];
        }

        return [
            'result' => $result,
            'warnings' => $warnings
        ];
    }

    /**
     * Parameter description for cancel_data_request().
     *
     * @return external_description
     */
    public static function cancel_data_request_returns() {
        return new external_single_structure([
            'result' => new external_value(PARAM_BOOL, 'The processing result'),
            'warnings' => new external_warnings()
        ]);
    }

    /**
     * Parameter description for contact_dpo().
     *
     * @return external_function_parameters
     */
    public static function contact_dpo_parameters() {
        return new external_function_parameters([
            'message' => new external_value(PARAM_TEXT, 'The user\'s message to the Data Protection Officer(s)', VALUE_REQUIRED)
        ]);
    }

    /**
     * Deny a data request.
     *
     * @param string $message The message to be sent to the DPO.
     * @return array
     * @throws coding_exception
     * @throws invalid_parameter_exception
     * @throws invalid_persistent_exception
     * @throws restricted_context_exception
     * @throws dml_exception
     * @throws moodle_exception
     */
    public static function contact_dpo($message) {
        global $USER;

        $warnings = [];
        $params = external_api::validate_parameters(self::contact_dpo_parameters(), [
            'message' => $message
        ]);
        $message = $params['message'];

        // Validate context.
        $userid = $USER->id;
        $context = context_user::instance($userid);
        self::validate_context($context);

        // Lodge the request.
        $datarequest = new data_request();
        // The user the request is being made for.
        $datarequest->set('userid', $userid);
        // The user making the request.
        $datarequest->set('requestedby', $userid);
        // Set status.
        $datarequest->set('status', api::DATAREQUEST_STATUS_PENDING);
        // Set request type.
        $datarequest->set('type', api::DATAREQUEST_TYPE_OTHERS);
        // Set request comments.
        $datarequest->set('comments', $message);

        // Store subject access request.
        $datarequest->create();

        // Get the list of the site Data Protection Officers.
        $dpos = api::get_site_dpos();

        // Email the data request to the Data Protection Officer(s)/Admin(s).
        $result = true;
        foreach ($dpos as $dpo) {
            $sendresult = api::notify_dpo($dpo, $datarequest);
            if (!$sendresult) {
                $result = false;
                $warnings[] = [
                    'item' => $dpo->id,
                    'warningcode' => 'errorsendingtodpo',
                    'message' => get_string('errorsendingmessagetodpo', 'tool_dataprivacy')
                ];
            }
        }

        return [
            'result' => $result,
            'warnings' => $warnings
        ];
    }

    /**
     * Parameter description for deny_data_request().
     *
     * @return external_description
     */
    public static function contact_dpo_returns() {
        return new external_single_structure([
            'result' => new external_value(PARAM_BOOL, 'The processing result'),
            'warnings' => new external_warnings()
        ]);
    }

    /**
     * Parameter description for get_data_request().
     *
     * @return external_function_parameters
     */
    public static function get_data_request_parameters() {
        return new external_function_parameters([
            'requestid' => new external_value(PARAM_INT, 'The request ID', VALUE_REQUIRED)
        ]);
    }

    /**
     * Fetch the details of a user's data request.
     *
     * @param int $requestid The request ID.
     * @return array
     * @throws coding_exception
     * @throws dml_exception
     * @throws invalid_parameter_exception
     * @throws restricted_context_exception
     * @throws moodle_exception
     */
    public static function get_data_request($requestid) {
        global $PAGE;

        $warnings = [];
        $params = external_api::validate_parameters(self::get_data_request_parameters(), [
            'requestid' => $requestid
        ]);
        $requestid = $params['requestid'];

        // Validate context.
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('tool/dataprivacy:managedatarequests', $context);

        $requestpersistent = new data_request($requestid);
        $exporter = new data_request_exporter($requestpersistent, ['context' => $context]);
        $renderer = $PAGE->get_renderer('tool_dataprivacy');
        $result = $exporter->export($renderer);

        return [
            'result' => $result,
            'warnings' => $warnings
        ];
    }

    /**
     * Parameter description for get_data_request().
     *
     * @return external_description
     */
    public static function get_data_request_returns() {
        return new external_single_structure([
            'result' => data_request_exporter::get_read_structure(),
            'warnings' => new external_warnings()
        ]);
    }

    /**
     * Parameter description for approve_data_request().
     *
     * @return external_function_parameters
     */
    public static function approve_data_request_parameters() {
        return new external_function_parameters([
            'requestid' => new external_value(PARAM_INT, 'The request ID', VALUE_REQUIRED)
        ]);
    }

    /**
     * Approve a data request.
     *
     * @param int $requestid The request ID.
     * @return array
     * @throws coding_exception
     * @throws dml_exception
     * @throws invalid_parameter_exception
     * @throws restricted_context_exception
     * @throws moodle_exception
     */
    public static function approve_data_request($requestid) {
        $warnings = [];
        $params = external_api::validate_parameters(self::approve_data_request_parameters(), [
            'requestid' => $requestid
        ]);
        $requestid = $params['requestid'];

        // Validate context.
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('tool/dataprivacy:managedatarequests', $context);

        // Ensure the request exists.
        $requestexists = data_request::record_exists($requestid);

        $result = false;
        if ($requestexists) {
            $result = api::approve_data_request($requestid);
        } else {
            $warnings[] = [
                'item' => $requestid,
                'warningcode' => 'errorrequestnotfound',
                'message' => get_string('errorrequestnotfound', 'tool_dataprivacy')
            ];
        }

        return [
            'result' => $result,
            'warnings' => $warnings
        ];
    }

    /**
     * Parameter description for approve_data_request().
     *
     * @return external_description
     */
    public static function approve_data_request_returns() {
        return new external_single_structure([
            'result' => new external_value(PARAM_BOOL, 'The processing result'),
            'warnings' => new external_warnings()
        ]);
    }

    /**
     * Parameter description for deny_data_request().
     *
     * @return external_function_parameters
     */
    public static function deny_data_request_parameters() {
        return new external_function_parameters([
            'requestid' => new external_value(PARAM_INT, 'The request ID', VALUE_REQUIRED)
        ]);
    }

    /**
     * Deny a data request.
     *
     * @param int $requestid The request ID.
     * @return array
     * @throws coding_exception
     * @throws dml_exception
     * @throws invalid_parameter_exception
     * @throws restricted_context_exception
     * @throws moodle_exception
     */
    public static function deny_data_request($requestid) {
        $warnings = [];
        $params = external_api::validate_parameters(self::deny_data_request_parameters(), [
            'requestid' => $requestid
        ]);
        $requestid = $params['requestid'];

        // Validate context.
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('tool/dataprivacy:managedatarequests', $context);

        // Ensure the request exists.
        $requestexists = data_request::record_exists($requestid);

        $result = false;
        if ($requestexists) {
            $result = api::deny_data_request($requestid);
        } else {
            $warnings[] = [
                'item' => $requestid,
                'warningcode' => 'errorrequestnotfound',
                'message' => get_string('errorrequestnotfound', 'tool_dataprivacy')
            ];
        }

        return [
            'result' => $result,
            'warnings' => $warnings
        ];
    }

    /**
     * Parameter description for deny_data_request().
     *
     * @return external_description
     */
    public static function deny_data_request_returns() {
        return new external_single_structure([
            'result' => new external_value(PARAM_BOOL, 'The processing result'),
            'warnings' => new external_warnings()
        ]);
    }

    /**
     * Parameter description for get_data_request().
     *
     * @return external_function_parameters
     */
    public static function get_users_parameters() {
        return new external_function_parameters([
            'query' => new external_value(PARAM_TEXT, 'The search query', VALUE_REQUIRED)
        ]);
    }

    /**
     * Fetch the details of a user's data request.
     *
     * @param string $query The search request.
     * @return array
     * @throws required_capability_exception
     * @throws dml_exception
     * @throws invalid_parameter_exception
     * @throws restricted_context_exception
     */
    public static function get_users($query) {
        $params = external_api::validate_parameters(self::get_users_parameters(), [
            'query' => $query
        ]);
        $query = $params['query'];

        // Validate context.
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('tool/dataprivacy:managedatarequests', $context);

        $allusernames = get_all_user_name_fields(true);
        // Exclude admins and guest user.
        $excludedusers = array_keys(get_admins()) + [guest_user()->id];
        $sort = 'lastname ASC, firstname ASC';
        $fields = 'id, email, ' . $allusernames;
        $users = get_users(true, $query, true, $excludedusers, $sort, '', '', 0, 30, $fields);
        $useroptions = [];
        foreach ($users as $user) {
            $useroptions[$user->id] = (object)[
                'id' => $user->id,
                'fullname' => fullname($user),
                'email' => $user->email
            ];
        }

        return $useroptions;
    }

    /**
     * Parameter description for get_users().
     *
     * @return external_description
     * @throws coding_exception
     */
    public static function get_users_returns() {
        return new external_multiple_structure(new external_single_structure(
            [
                'id' => new external_value(core_user::get_property_type('id'), 'ID of the user'),
                'fullname' => new external_value(core_user::get_property_type('firstname'), 'The fullname of the user'),
                'email' => new external_value(core_user::get_property_type('email'), 'The user\'s email address', VALUE_OPTIONAL),
            ]
        ));
    }

    /**
     * Parameter description for create_purpose_form().
     *
     * @return external_function_parameters
     */
    public static function create_purpose_form_parameters() {
        return new external_function_parameters([
            'jsonformdata' => new external_value(PARAM_RAW, 'The data to create the purpose, encoded as a json array')
        ]);
    }

    /**
     * Creates a data purpose from form data.
     *
     * @param string $jsonformdata
     * @return array
     */
    public static function create_purpose_form($jsonformdata) {
        global $PAGE;

        $warnings = [];

        $params = external_api::validate_parameters(self::create_purpose_form_parameters(), [
            'jsonformdata' => $jsonformdata
        ]);

        self::validate_context(\context_system::instance());

        $serialiseddata = json_decode($params['jsonformdata']);
        $data = array();
        parse_str($serialiseddata, $data);

        $purpose = new \tool_dataprivacy\purpose(0);
        $mform = new \tool_dataprivacy\form\purpose(null, ['persistent' => $purpose], 'post', '', null, true, $data);

        $validationerrors = true;
        if ($validateddata = $mform->get_data()) {
            $purpose = api::create_purpose($validateddata);
            $validationerrors = false;
        } else if ($errors = $mform->is_validated()) {
            throw new moodle_exception('generalerror');
        }

        $exporter = new purpose_exporter($purpose, ['context' => \context_system::instance()]);
        return [
            'purpose' => $exporter->export($PAGE->get_renderer('core')),
            'validationerrors' => $validationerrors,
            'warnings' => $warnings
        ];
    }

    /**
     * Returns for create_purpose_form().
     *
     * @return external_single_structure
     */
    public static function create_purpose_form_returns() {
        return new external_single_structure([
            'purpose' => purpose_exporter::get_read_structure(),
            'validationerrors' => new external_value(PARAM_BOOL, 'Were there validation errors', VALUE_REQUIRED),
            'warnings' => new external_warnings()
        ]);
    }

    /**
     * Parameter description for delete_purpose().
     *
     * @return external_function_parameters
     */
    public static function delete_purpose_parameters() {
        return new external_function_parameters([
            'id' => new external_value(PARAM_INT, 'The purpose ID', VALUE_REQUIRED)
        ]);
    }

    /**
     * Deletes a data purpose.
     *
     * @param int $id The ID.
     * @return array
     * @throws invalid_persistent_exception
     * @throws coding_exception
     * @throws invalid_parameter_exception
     */
    public static function delete_purpose($id) {
        global $USER;

        $params = external_api::validate_parameters(self::delete_purpose_parameters(), [
            'id' => $id
        ]);

        $result = api::delete_purpose($params['id']);

        return [
            'result' => $result,
            'warnings' => []
        ];
    }

    /**
     * Parameter description for delete_purpose().
     *
     * @return external_single_structure
     */
    public static function delete_purpose_returns() {
        return new external_single_structure([
            'result' => new external_value(PARAM_BOOL, 'The processing result'),
            'warnings' => new external_warnings()
        ]);
    }

    /**
     * Parameter description for create_category_form().
     *
     * @return external_function_parameters
     */
    public static function create_category_form_parameters() {
        return new external_function_parameters([
            'jsonformdata' => new external_value(PARAM_RAW, 'The data to create the category, encoded as a json array')
        ]);
    }

    /**
     * Creates a data category from form data.
     *
     * @param string $jsonformdata
     * @return array
     */
    public static function create_category_form($jsonformdata) {
        global $PAGE;

        $warnings = [];

        $params = external_api::validate_parameters(self::create_category_form_parameters(), [
            'jsonformdata' => $jsonformdata
        ]);

        self::validate_context(\context_system::instance());

        $serialiseddata = json_decode($params['jsonformdata']);
        $data = array();
        parse_str($serialiseddata, $data);

        $category = new \tool_dataprivacy\category(0);
        $mform = new \tool_dataprivacy\form\category(null, ['persistent' => $category], 'post', '', null, true, $data);

        $validationerrors = true;
        if ($validateddata = $mform->get_data()) {
            $category = api::create_category($validateddata);
            $validationerrors = false;
        } else if ($errors = $mform->is_validated()) {
            throw new moodle_exception('generalerror');
        }

        $exporter = new category_exporter($category, ['context' => \context_system::instance()]);
        return [
            'category' => $exporter->export($PAGE->get_renderer('core')),
            'validationerrors' => $validationerrors,
            'warnings' => $warnings
        ];
    }

    /**
     * Returns for create_category_form().
     *
     * @return external_single_structure
     */
    public static function create_category_form_returns() {
        return new external_single_structure([
            'category' => category_exporter::get_read_structure(),
            'validationerrors' => new external_value(PARAM_BOOL, 'Were there validation errors', VALUE_REQUIRED),
            'warnings' => new external_warnings()
        ]);
    }

    /**
     * Parameter description for delete_category().
     *
     * @return external_function_parameters
     */
    public static function delete_category_parameters() {
        return new external_function_parameters([
            'id' => new external_value(PARAM_INT, 'The category ID', VALUE_REQUIRED)
        ]);
    }

    /**
     * Deletes a data category.
     *
     * @param int $id The ID.
     * @return array
     * @throws invalid_persistent_exception
     * @throws coding_exception
     * @throws invalid_parameter_exception
     */
    public static function delete_category($id) {
        global $USER;

        $params = external_api::validate_parameters(self::delete_category_parameters(), [
            'id' => $id
        ]);

        $result = api::delete_category($params['id']);

        return [
            'result' => $result,
            'warnings' => []
        ];
    }

    /**
     * Parameter description for delete_category().
     *
     * @return external_single_structure
     */
    public static function delete_category_returns() {
        return new external_single_structure([
            'result' => new external_value(PARAM_BOOL, 'The processing result'),
            'warnings' => new external_warnings()
        ]);
    }

    /**
     * Parameter description for set_contextlevel_form().
     *
     * @return external_function_parameters
     */
    public static function set_contextlevel_form_parameters() {
        return new external_function_parameters([
            'jsonformdata' => new external_value(PARAM_RAW, 'The context level data, encoded as a json array')
        ]);
    }

    /**
     * Creates a data category from form data.
     *
     * @param string $jsonformdata
     * @return array
     */
    public static function set_contextlevel_form($jsonformdata) {
        global $PAGE;

        $warnings = [];

        $params = external_api::validate_parameters(self::set_contextlevel_form_parameters(), [
            'jsonformdata' => $jsonformdata
        ]);

        self::validate_context(\context_system::instance());

        $serialiseddata = json_decode($params['jsonformdata']);
        $data = array();
        parse_str($serialiseddata, $data);

        $persistent = \tool_dataprivacy\contextlevel::get_record_by_contextlevel($data['contextlevel'], false);
        if (!$persistent) {
            $persistent = new \tool_dataprivacy\contextlevel();
        }

        $purposeoptions = data_registry_page::purpose_options(
            \tool_dataprivacy\api::get_purposes()
        );
        $categoryoptions = data_registry_page::category_options(
            \tool_dataprivacy\api::get_categories()
        );

        $customdata = [
            'contextlevel' => $data['contextlevel'],
            'contextlevelname' => get_string('contextlevelname' . $data['contextlevel'], 'tool_dataprivacy'),
            'persistent' => $persistent,
            'purposes' => $purposeoptions,
            'categories' => $categoryoptions,
        ];
        $mform = new \tool_dataprivacy\form\contextlevel(null, $customdata, 'post', '', null, true, $data);
        if ($validateddata = $mform->get_data()) {
            $contextlevel = api::set_contextlevel($validateddata);
        } else if ($errors = $mform->is_validated()) {
            $warnings[] = json_encode($errors);
            throw new moodle_exception('generalerror');
        }

        if ($contextlevel) {
            $result = true;
        } else {
            $result = false;
        }
        return [
            'result' => $result,
            'warnings' => $warnings
        ];
    }

    /**
     * Returns for set_contextlevel_form().
     *
     * @return external_single_structure
     */
    public static function set_contextlevel_form_returns() {
        return new external_single_structure([
            'result' => new external_value(PARAM_BOOL, 'Whether the data was properly set or not'),
            'warnings' => new external_warnings()
        ]);
    }

    /**
     * Parameter description for set_context_form().
     *
     * @return external_function_parameters
     */
    public static function set_context_form_parameters() {
        return new external_function_parameters([
            'jsonformdata' => new external_value(PARAM_RAW, 'The context level data, encoded as a json array')
        ]);
    }

    /**
     * Creates a data category from form data.
     *
     * @param string $jsonformdata
     * @return array
     */
    public static function set_context_form($jsonformdata) {
        global $PAGE;

        $warnings = [];

        $params = external_api::validate_parameters(self::set_context_form_parameters(), [
            'jsonformdata' => $jsonformdata
        ]);

        self::validate_context(\context_system::instance());

        $serialiseddata = json_decode($params['jsonformdata']);
        $data = array();
        parse_str($serialiseddata, $data);

        $context = \context_helper::instance_by_id($data['contextid']);

        $persistent = \tool_dataprivacy\context_instance::get_record_by_contextid($data['contextid'], false);
        if (!$persistent) {
            $persistent = new \tool_dataprivacy\context_instance();
        }

        $purposeoptions = data_registry_page::purpose_options(
            \tool_dataprivacy\api::get_purposes()
        );
        $categoryoptions = data_registry_page::category_options(
            \tool_dataprivacy\api::get_categories()
        );

        $customdata = [
            'context' => $context,
            'subjectscope' => \tool_dataprivacy\api::get_subject_scope($context),
            'contextname' => $context->get_context_name(),
            'persistent' => $persistent,
            'purposes' => $purposeoptions,
            'categories' => $categoryoptions,
        ];
        $mform = new \tool_dataprivacy\form\context_instance(null, $customdata, 'post', '', null, true, $data);
        if ($validateddata = $mform->get_data()) {
            $context = api::set_context_instance($validateddata);
        } else if ($errors = $mform->is_validated()) {
            $warnings[] = json_encode($errors);
            throw new moodle_exception('generalerror');
        }

        if ($context) {
            $result = true;
        } else {
            $result = false;
        }
        return [
            'result' => $result,
            'warnings' => $warnings
        ];
    }

    /**
     * Returns for set_context_form().
     *
     * @return external_single_structure
     */
    public static function set_context_form_returns() {
        return new external_single_structure([
            'result' => new external_value(PARAM_BOOL, 'Whether the data was properly set or not'),
            'warnings' => new external_warnings()
        ]);
    }

    /**
     * Parameter description for tree_extra_branches().
     *
     * @return external_function_parameters
     */
    public static function tree_extra_branches_parameters() {
        return new external_function_parameters([
            'contextid' => new external_value(PARAM_INT, 'The context id to expand'),
            'element' => new external_value(PARAM_ALPHA, 'The element we are interested on')
        ]);
    }

    /**
     * Returns tree extra branches.
     *
     * @param int $contextid
     * @param string $element
     * @return array
     */
    public static function tree_extra_branches($contextid, $element) {

        $params = external_api::validate_parameters(self::tree_extra_branches_parameters(), [
            'contextid' => $contextid,
            'element' => $element,
        ]);

        $context = \context_helper::instance_by_id($params['contextid']);

        self::validate_context($context);
        api::check_can_manage_data_registry($context->id);

        switch ($params['element']) {
            case 'course':
                $branches = data_registry_page::get_courses_branch($context);
                break;
            case 'module':
                $branches = data_registry_page::get_modules_branch($context);
                break;
            case 'block':
                $branches = data_registry_page::get_blocks_branch($context);
                break;
            default:
                throw new \moodle_exception('Unsupported element provided.');
        }

        return [
            'branches' => $branches,
            'warnings' => [],
        ];
    }

    /**
     * Returns for tree_extra_branches().
     *
     * @return external_single_structure
     */
    public static function tree_extra_branches_returns() {
        return new external_single_structure([
            'branches' => new external_multiple_structure(self::get_tree_node_structure(true)),
            'warnings' => new external_warnings()
        ]);
    }

    /**
     * Gets the structure of a tree node (link + child branches).
     *
     * @param bool $allowchildbranches
     * @return array
     */
    private static function get_tree_node_structure($allowchildbranches = true) {
        $fields = [
            'text' => new external_value(PARAM_TEXT, 'The node text', VALUE_REQUIRED),
            'expandcontextid' => new external_value(PARAM_INT, 'The contextid this node expands', VALUE_REQUIRED),
            'expandelement' => new external_value(PARAM_ALPHA, 'What element is this node expanded to', VALUE_REQUIRED),
            'contextid' => new external_value(PARAM_INT, 'The node contextid', VALUE_REQUIRED),
            'contextlevel' => new external_value(PARAM_INT, 'The node contextlevel', VALUE_REQUIRED),
            'expanded' => new external_value(PARAM_INT, 'Is it expanded', VALUE_REQUIRED),
        ];

        if ($allowchildbranches) {
            // Passing false as we will not have more than 1 sub-level.
            $fields['branches'] = new external_multiple_structure(
                self::get_tree_node_structure(false),
                'Children node structure',
                VALUE_OPTIONAL
            );
        } else {
            // We will only have 1 sub-level and we don't want an infinite get_tree_node_structure, this is a hacky
            // way to prevent this infinite loop when calling get_tree_node_structure recursively.
            $fields['branches'] = new external_multiple_structure(
                new external_value(
                    PARAM_TEXT,
                    'Nothing really, it will always be an empty array',
                    VALUE_OPTIONAL
                )
            );
        }

        return new external_single_structure($fields, 'Node structure', VALUE_OPTIONAL);
    }
}
