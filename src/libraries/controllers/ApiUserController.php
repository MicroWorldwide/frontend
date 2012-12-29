<?php
/**
  * User controller for API endpoints.
  *
  * @author Jaisen Mathai <jaisen@jmathai.com>
 */
class ApiUserController extends ApiBaseController
{
  /**
    * Call the parent constructor
    *
    * @return void
    */
  public function __construct()
  {
    parent::__construct();
    $this->user = new User;
  }

  /**
    * Log a user in via BrowserID
    *
    * @return string Standard JSON envelope
    */
  public function login($provider = null)
  {
    $wasUserLoggedIn = $this->user->login($provider, $_POST);
    if($wasUserLoggedIn)
      return $this->success('User was logged in successfully', array('email' => getSession()->get('email')));
    else
      return $this->forbidden('User was not able to be logged in', false);
  }

  /**
    * Log a user out.
    *
    * @return string Standard JSON envelope
    */
  public function logout()
  {
    $this->user->logout();
    return $this->success('User was logged out successfully', true);
  }

  /**
    * Generate a password reset token and email a link to the user.
    *
    * @return string Standard JSON envelope
    */
  public function passwordRequest()
  {
    if(!isset($_POST['email']))
      return $this->error('No email address provided.', false);

    $email = $_POST['email'];
    if($email == $this->config->user->email)
    {
      $token = md5(rand(10000,100000));
      $tokenUrl = sprintf('%s://%s/manage/password/reset/%s', $this->utility->getProtocol(false), $_SERVER['HTTP_HOST'], $token);
      $this->user->setAttribute('passwordToken', $token);
      $templateObj = getTemplate();
      $template = sprintf('%s/email/password-reset.php', $this->config->paths->templates);
      $body = $this->template->get($template, array('tokenUrl' => $tokenUrl));
      $emailer = new Emailer;
      $emailer->setRecipients(array($this->config->user->email));
      $emailer->setSubject('OpenPhoto password reset request');
      $emailer->setBody($body);
      $result = $emailer->send();
      if($result > 0)
      {
        return $this->success('An email was sent to reset the password.', true);
      }
      else
      {
        $this->logger->info('Unable to send email. Confirm that your email settings are correct and the email addresses are valid.');
        return $this->error('We were unable to send a password reset email.', false);
      }
    }
    return $this->error('The email address provided does not match the registered email for this site.', false);
  }

  /**
    * Resets a user's password after validating the password token
    *
    * @return string Standard JSON envelope
    */
  public function passwordReset()
  {
    $user = new User;
    $token = $_POST['token'];
    $password = $_POST['password'];
    $passwordConfirm = $_POST['password-confirm'];
    $tokenFromDb = $user->getAttribute('passwordToken');
    if($tokenFromDb != $token)
      return $this->error('Could not validate password reset token.', false);
    elseif($password !== $passwordConfirm)
      return $this->error('Password confirmation did not match.', false);

    $this->user->update(array('password' => $password));
    $this->user->setAttribute('passwordToken', null);
    return $this->success('Password was updated successfully.', true);
  }

  public function profile()
  {
    $email = $this->session->get('email');
    $user = $this->user->getUserRecord();
    if(empty($user))
      return $this->notFound('Could not load user profile');

    $utilityObj = new Utility;
    $profile = array(
      'id' => $utilityObj->getHost(),
      'photoUrl' => $this->user->getAvatarFromEmail(100, $this->config->user->email),
      //'photoId' => '',
      'name' => $this->user->getNameFromEmail($this->config->user->email)
    );

    if(isset($_GET['includeViewer']) && $_GET['includeViewer'] == '1')
    {
      $viewer = null;
      if($email !== null)
        $viewer = $this->user->getUserByEmail($email);

      if($viewer !== null)
      {
        $profile['viewer'] = array(
          'id' => $viewer['id'],
          'photoUrl' => $this->user->getAvatarFromEmail(100, $viewer['id']),
          'name' => $this->user->getNameFromEmail($viewer['id'])
        );
      }
    }

    if($this->user->isLoggedIn())
    {
      if($user['id'] == $this->session->get('email'))
        $profile['isOwner'] = true;
      else
        $profile['isOwner'] = false;
    }

    if($this->user->isAdmin())
      $profile['email'] = $this->user->getEmailAddress();

    return $this->success('User profile', $profile);
  }

  public function profilePost()
  {
    getAuthentication()->requireAuthentication(true);
    getAuthentication()->requireCrumb();
    $params = array();
    if(isset($_POST['photoId']))
    {
      $photoAttribute = $this->user->getAttributeName('profilePhoto');
      if($_POST['photoId'] == '')
      {
        $params[$photoAttribute] = null;
      }
      else
      {
        $apiResp = $this->api->invoke(sprintf('/photo/%s/view.json', $_POST['photoId']), EpiRoute::httpGet, array('_GET' => array('returnSizes' => '100x100xCR', 'generate' => 'true')));
        if($apiResp['code'] !== 200)
          return $this->error('Could not fetch profile photo', false);

        $params[$photoAttribute] = $apiResp['result']['path100x100xCR'];
      }
    }

    if(isset($_POST['name']))
    {
      $params[$this->user->getAttributeName('profileName')] = strip_tags($_POST['name']);
    }

    if(!empty($params))
    {
      if(!$this->user->update($params))
        return $this->error('Could not update profile', false);
    }

    $apiUserResp = $this->api->invoke('/user/profile.json', EpiRoute::httpGet);
    if($apiUserResp['code'] !== 200)
      return $this->error('Profile updated but could not retrieve', false);

    return $this->success('Profile updated', $apiUserResp['result']);
  }
}
