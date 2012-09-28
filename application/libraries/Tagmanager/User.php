<?php
/**
 * Ionize
 *
 * @package		Ionize
 * @author		Ionize Dev Team
 * @license		http://ionizecms.com/doc-license
 * @link		http://ionizecms.com
 * @since		Version 0.9.9
 *
 */


/**
 * User TagManager
 *
 */
class TagManager_User extends TagManager
{
	/**
	 * TRUE if the form data were processed
	 * @var bool
	 */
	protected static $processed = FALSE;


	/**
	 * Stores the current user
	 *
	 * @var null
	 *
	 */
	protected static $user = NULL;


	/**
	 * Stores the current user's group
	 *
	 * @var null
	 *
	 */
	protected static $group = NULL;


	/**
	 * Only few tags are described here
	 * Common tags are set dynamically, from DB fields.
	 *
	 * @var array
	 */
	public static $tag_definitions = array
	(
		// User data
		'user' => 					'tag_user',
		'user:name' => 				'tag_user_name',
		'user:activation_key' =>	'tag_simple_value',
		'user:group' => 			'tag_user_group',
		'user:group:name' => 		'tag_user_group_name',
		'user:group:title' => 		'tag_user_group_title',

		// Expands the tag if the user is logged in
		'user:logged' =>		'tag_user_logged',
	);


	// ------------------------------------------------------------------------


	/**
	 * Parent <ion:user /> tag
	 *
	 * @param 	FTL_Binding
	 *
	 * @return 	string
	 *
	 */
	public static function tag_user(FTL_Binding $tag)
	{
		self::load_model('users_model');
		self::load_model('group_model');

		// Do these once
		if (self::$processed === FALSE)
		{
			// To avoid looping if process data calls "<ion:user />" again
			self::$processed = TRUE;

			// Set dynamics tags
			$user_fields = self::$ci->users_model->field_data();
			$group_fields = self::$ci->group_model->field_data();

			foreach($user_fields as $field => $info)
			{
				if (in_array($info['type'], array('date', 'datetime', 'timestamp')))
					self::$context->define_tag('user:' . $field, array(__CLASS__, 'tag_simple_date'));
				else
					self::$context->define_tag('user:' . $field, array(__CLASS__, 'tag_simple_value'));
			}
			// Group data are also available in the user array
			foreach($group_fields as $field => $info)
			{
				self::$context->define_tag('user:group:' . $field, array(__CLASS__, 'tag_simple_value'));
			}

			// Process form data
			self::process_data($tag);

			// Set the current user
			self::$user = Connect()->get_current_user();

		}

		// Do this everytime the tag is called
		if (self::$user) {
			$tag->set('user', self::$user);
			$tag->set('group', self::$user['group']);
		}

		return $tag->expand();
	}


	// ------------------------------------------------------------------------


	/**
	 * Expands the children if the user is logged in.
	 *
	 * @param FTL_Binding $tag
	 *
	 * @return string
	 */
	public static function tag_user_logged(FTL_Binding $tag)
	{
		$tag->setAsProcessTag();

		$is = $tag->getAttribute('is');

		if (is_null($is)) $is = TRUE;

		if (Connect()->logged_in() == $is)
		{
			if (self::$trigger_else > 0)
				self::$trigger_else--;
			return $tag->expand();
		}
		else
		{
			self::$trigger_else++;
			return '';
		}
	}


	// ------------------------------------------------------------------------


	/**
	 * Returns the found name of the user
	 * 1. Screen name if set
	 * 2. Firstname and Lastname if screen name not set
	 *
	 * @param FTL_Binding $tag
	 *
	 * @return string
	 *
	 */
	public static function tag_user_name(FTL_Binding $tag)
	{
		$value = $tag->getValue('screen_name');
		if (is_null($value) OR $value == '')
		{
			$value = $tag->getValue('firstname') . ' ' . $tag->getValue('lastname');
		}
		return self::output_value($tag, $value);
	}


	// ------------------------------------------------------------------------


	/**
	 * Current user's group
	 *
	 * @param FTL_Binding $tag
	 *
	 * @return string
	 *
	 */
	public static function tag_user_group(FTL_Binding $tag)
	{
		if (isset(self::$user['group']))
			$tag->set('group', self::$user['group']);

		return $tag->expand();
	}


	// ------------------------------------------------------------------------


	/**
	 * More logical key for 'slug' in group table.
	 * @TODO : Correct 'slug' in DB and replace it by 'name'
	 *
	 * @param FTL_Binding $tag
	 *
	 * @return string
	 *
	 */
	public static function tag_user_group_name(FTL_Binding $tag)
	{
		$name = $tag->getValue('slug', 'group');

		return self::output_value($tag, $name);
	}


	// ------------------------------------------------------------------------


	/**
	 * More logical key for 'group_name' in group table.
	 * @TODO : Correct 'group_name' in DB and replace it by 'title'
	 *
	 * @param FTL_Binding $tag
	 *
	 * @return string
	 *
	 */
	public static function tag_user_group_title(FTL_Binding $tag)
	{
		$group_name = $tag->getValue('group_name', 'group');

		return self::output_value($tag, $group_name);
	}


	// ------------------------------------------------------------------------


	/**
	 * Executed the very first time the <ion:user /> tag is called.
	 * Processes the form POST data.
	 *
	 * @param FTL_Binding $tag
	 *
	 * @return bool
	 *
	 */
	protected function process_data(FTL_Binding $tag)
	{
		$form_name = self::$ci->input->post('form');

		// Form settings
		$form_settings = TagManager_Form::get_form_settings($form_name);

		if ($form_name)
		{
			switch ($form_name)
			{
				// Logout
				case 'logout':

					if (Connect()->logged_in())
					{
						Connect()->logout();
						$tag->remove('user');
					}
					break;

				// Login
				case 'login':

					if (TagManager_Form::validate('login'))
					{
						if ( ! Connect()->logged_in())
						{
							$email = self::$ci->input->post('email');
							$db_user = Connect()->find_user(array('email'=>$email));

							if ($db_user)
							{
								// Account not allowed to login
								if ($db_user['group']['level'] < 100)
								{
									TagManager_Form::set_additional_error('login', lang($form_settings['not_activated']));
								}
								else
								{
									$user = array(
										'email' => $email,
										'password' => self::$ci->input->post('password')
									);

									$result = Connect()->login($user);

									if ($result)
										TagManager_Form::set_additional_success('login', lang($form_settings['success']));
									else
										TagManager_Form::set_additional_error('login', lang($form_settings['error']));
								}
							}
							else
							{
								TagManager_Form::set_additional_error('login', lang($form_settings['not_found']));
							}
						}
					}
					break;

				// Register
				case 'register':

					if (TagManager_Form::validate('register'))
					{
						// Get user's allowed fields
						$fields = TagManager_Form::get_form_fields('register');
						if ( is_null($fields))
							show_error('No definition for the form "register"');

						$fields = array_fill_keys($fields, FALSE);
						$user = array_merge($fields, self::$ci->input->post());

						// Compliant with Connect, based on username
						$user['username'] = $user['email'];
						$user['join_date'] = date('Y-m-d H:i:s');

						if ( ! Connect()->register($user))
						{
							TagManager_Form::set_additional_error('register', lang('form_register_error_message'));
						}
						else
						{
							// Get the user saved in DB
							$user = Connect()->get_user($user['username']);
							$user['password'] = Connect()->decrypt($user['password'], $user);
							$user['activation_key'] = Connect()->calc_activation_key($user);

							// Send Emails
							self::send_emails($tag, 'register', $user);

							TagManager_Form::set_additional_success('register', lang($form_settings['success']));
						}
					}
					break;

				// Get new password
				case 'password':

					if (TagManager_Form::validate('password'))
					{
						$user = Connect()->find_user(array(
							'email' => self::$ci->input->post('email')
						));

						if ($user)
						{
							// Save the user with this new password
							$new_password = Connect()->get_random_password(8);
							$user['password'] = $new_password;

							if ( ! Connect()->update($user))
							{
								TagManager_Form::set_additional_error('password', lang($form_settings['error']));
							}
							else
							{
								// Get the user again, to calculate his activation key
								$user = Connect()->find_user($user['username']);
								$activation_key = Connect()->calc_activation_key($user);

								// Put the clear password to the user's data, for the email
								$user['password'] = $new_password;
								$user['activation_key'] = $activation_key;

								// Send Emails
								self::send_emails($tag, 'password', $user);

								TagManager_Form::set_additional_success('password', lang($form_settings['success']));
							}
						}
						else
						{
							TagManager_Form::set_additional_error('password', lang($form_settings['not_found']));
						}
					}

					break;

				// Activate account
				case 'activation':

					break;

				// Save profile
				case 'profile':

					// Lost connection
					if(($current_user = Connect()->get_current_user()) == FALSE)
					{
						TagManager_Form::set_additional_error('profile', lang('form_not_logged'));
						return FALSE;
					}

					// Delete the profile
					if (self::$ci->input->post('delete'))
					{
						$result = Connect()->delete($current_user);
						Connect()->logout();
						TagManager_Form::set_additional_success('profile', lang('form_profile_account_deleted'));
					}
					else
					{
						if (TagManager_Form::validate('profile'))
						{
							$fields = TagManager_Form::get_form_fields('profile');
							if ( is_null($fields))
								show_error('No definition for the form "profile"');

							$fields = array_fill_keys($fields, FALSE);
							$user = array_merge($fields, self::$ci->input->post());

							// Compliant with Connect, based on username
							$user['username'] = $user['email'];
							$user['id_user'] = $current_user['id_user'];

							// Checkboxes and multiselect
							foreach($user as $key => $data)
							{
								if (is_array($data))
									$user[$key] = implode(',', $data);
							}

							$result = Connect()->update($user);

							// If error here, it can only be on the email, which already exists in the DB
							if ( ! $result)
							{
								TagManager_Form::set_additional_error('email', lang($form_settings['error']));
							}
							else
							{
								TagManager_Form::set_additional_success('profile', lang($form_settings['success']));
							}
						}
					}
					break;
			}
		}
	}


	// ------------------------------------------------------------------------


	protected function send_emails(FTL_Binding $tag, $form_name, $user)
	{
		$emails = TagManager_Form::get_form_emails($form_name);
		$website_email = Settings::get('site_email') ? Settings::get('site_email') : NULL;

		foreach($emails as $email_setting)
		{
			$email = $email_setting['email'];

			// Get potential website / user email
			switch($email)
			{
				case 'website':
					$email = (Settings::get('site_email') != '') ? Settings::get('site_email') : NULL;
					break;

				case 'user':
					$email = isset($user['email']) ? $user['email'] : NULL;
					break;

				default:
					$email = NULL;
					break;
			}

			// Send the email
			if ( ! is_null($email))
			{
				$subject = lang($email_setting['subject'], Settings::get('site_title'));

				// Tag data. Current context : <ion:user />
				$tag->set('email_subject', $subject);

				// Data has to be set manually, because when called, <ion:user /> gets the current user
				// and if no user is connected, these data will be empty, so not available for the email
				// template
				$tag->set('user', $user);
				$tag->set('group', $user['group']);

				// Email Lib
				if ( ! isset(self::$ci->email)) self::$ci->load->library('email');

				// Subject / From / To
				self::$ci->email->subject($subject);
				self::$ci->email->from($website_email, Settings::get("site_title"));
				self::$ci->email->to($email);

				// View
				$view_content = $tag->parse_as_nested(Theme::load($email_setting['view']));

				self::$ci->email->message($view_content);

				// Send silently
				@self::$ci->email->send();
			}
		}
	}
}