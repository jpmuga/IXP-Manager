<?php

/*
 * Copyright (C) 2009-2011 Internet Neutral Exchange Association Limited.
 * All Rights Reserved.
 * 
 * This file is part of IXP Manager.
 * 
 * IXP Manager is free software: you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the Free
 * Software Foundation, version v2.0 of the License.
 * 
 * IXP Manager is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE.  See the GNU General Public License for
 * more details.
 * 
 * You should have received a copy of the GNU General Public License v2.0
 * along with IXP Manager.  If not, see:
 * 
 * http://www.gnu.org/licenses/gpl-2.0.html
 */


/*
 *
 *
 * http://www.inex.ie/
 * (c) Internet Neutral Exchange Association Ltd
 */

class AuthController extends INEX_Controller_Action
{

    public function indexAction()
    {
        $this->_forward( 'login' );
    }

    public function loginAction()
    {
        $this->view->display( 'auth/login.tpl' );
    }

    public function logoutAction()
    {
        $this->view->clear_all_assign();

        $auth = Zend_Auth::getInstance();

        if( $auth->hasIdentity() )
        {
            $auth->clearIdentity();
            $this->view->message = new INEX_Message( 'You have been logged out', INEX_Message::MESSAGE_TYPE_INFO );
        }

        if( $this->_request->getParam( 'auto', 0 ) == 1 )
            $this->view->message = new INEX_Message( 'To protect your account and its information, '
                . 'you have been logged out automatically.', INEX_Message::MESSAGE_TYPE_ALERT );


        Zend_Session::destroy( true, true );
        $this->view->display( 'auth/login.tpl' );
    }

    public function processAction()
    {
        $auth = Zend_Auth::getInstance();

        try
        {
            $authAdapter = new INEX_Auth_DoctrineAdapter(
                mb_strtolower( $this->getRequest()->getParam( 'loginusername' ) ),
                $this->getRequest()->getParam( 'loginpassword' )
            );
        }
        catch( Zend_Auth_Adapter_Exception $e )
        {
            $this->view->message = new INEX_Message( $e->getMessage(), 'error' );
            $this->view->display( 'auth/login.tpl' );
            return false;
        }

        $result = $auth->authenticate( $authAdapter );

        switch( $result->getCode() )
        {
            case Zend_Auth_Result::FAILURE_IDENTITY_NOT_FOUND:
            case Zend_Auth_Result::FAILURE_CREDENTIAL_INVALID:
                $this->view->message = new INEX_Message( implode( "<br />\n", $result->getMessages() ), 'error' );
                $this->view->display( 'auth/login.tpl' );
                return false;
                break;

            case Zend_Auth_Result::SUCCESS:
                $identity = $auth->getIdentity();
                $user = Doctrine::getTable( 'User' )->find( $identity['user']['id'] );

                // record the last login IP address
                if( $ip = $user->hasPreference( 'auth.last_login_from' ) )
                {
                    $this->session->last_login_from = $ip;
                    $this->session->last_login_at   = $user->getPreference( 'auth.last_login_at' );
                }
                else
                    $this->session->last_login_from = '';

                $user->setPreference( 'auth.last_login_from', $_SERVER['REMOTE_ADDR'] );
                $user->setPreference( 'auth.last_login_at',   mktime()                );

                // set the timeout
                $this->session->timeOfLastAction = mktime();

                if( isset( $this->session->postAuthRedirect ) )
                    $this->_redirect( $this->session->postAuthRedirect );
                else
                    $this->_redirect( '' );
                break;

            default:
                break;
        }
    }

    public function forgottenPasswordAction()
    {
        if( $this->getRequest()->getParam( 'fpsubmitted', false ) )
        {
            // does the username exist?
            if( $user = Doctrine_Core::getTable( 'User' )->findOneByUsername( $this->getRequest()->getParam( 'loginusername' ) ) )
            {
                // sanity checks
                if( is_numeric( $user->authorisedMobile ) && strlen( $user->authorisedMobile ) > 10 )
                {
                    $sms = new INEX_SMS_Clickatell(
                        $this->config['sms']['clickatell']['username'],
                        $this->config['sms']['clickatell']['password'],
                        $this->config['sms']['clickatell']['api_id'],
                        $this->config['sms']['clickatell']['sender_id']
                    );

                    if( $sms->send( $user->authorisedMobile, "Your " . $this->_config['identity']['orgname']
                            . " Members' area password is:\n\n" . $user->password . "\n" ) )
                    {
                        $this->view->message = new INEX_Message(
                            'Your password has been sent to the authorised mobile ('
                                . substr( $user->authorisedMobile, 0, 6 )
                                . str_repeat( 'x', strlen( $user->authorisedMobile ) - 8 )
                                . substr( $user->authorisedMobile, -2 ),
                            'success'
                        );

                        $this->view->display( 'auth/login.tpl' );
                        return true;
                    }
                    else
		            {
		                $this->view->message = new INEX_Message(
		                	'We could not send the password by SMS due to an issue with our SMS provider. '
		                    . 'Please contact us on ' . $this->_config['identity']['email'] . '.', 'error' );
		            }
                }
                else
                {
                    $this->view->message = new INEX_Message(
                        'We could not send the password by SMS as we do not have an authorised mobile number on file. '
                        . 'Please contact us on <em>' . $this->_config['identity']['email'] . '</em> from an official '
                        . 'company email address to provide a mobile number.', 'alert'
                    );
                }
            }
            else
            {
                $this->view->message = new INEX_Message( 'That username does not exist', 'error' );
            }
        }

        $this->view->display( 'auth/forgotten-password.tpl' );
    }

    /**
     * Create a Drupal login button for admin users
     */
    protected function drupalLoginAction()
    {
        // let's be clear - you have to be an INEX member to access this!
        if( $this->identity['user']['privs'] == User::AUTH_SUPERUSER )
            $this->view->display( 'auth/drupal-login.tpl' );
        else
            $this->_forward( 'index', 'dashboard' );
    }

    /**
     * Switch the logged in user to another.
     *
     * Allows administrators to switch to another user and operate as them temporarily.
     */
    public function switchAction()
    {
        // only super admins can switch user!
        if( $this->user['privs'] != User::AUTH_SUPERUSER )
        {
            $this->logger->notice( 'User ' . $this->user['username'] . ' tried to switch to user with ID '
                . $this->_request->getParam( 'id', '[unknown]' ) );
            $this->session->message = new INEX_Message(
                'You are not allowed to switch users! This attempt has been logged and the administrators notified.',
                INEX_Message::MESSAGE_TYPE_ERROR
            );

            if( $this->user['privs'] == User::AUTH_CUSTADMIN )
            {
                $this->_redirect( 'cust-admin/users' );
            }
            else
            {
                $this->_redirect( 'dashboard' );
            }
        }

        // store the fact that we're switching in the session
        $this->session->switched_user_from = $this->user['id'];

        // does the requested user exist
        $nu = Doctrine_Core::getTable( 'User' )->find( $this->_request->getParam( 'id', false ) );

        if( !$nu )
        {
            $this->session->message = new INEX_Message(
                'The requested user does not exist',
                INEX_Message::MESSAGE_TYPE_ERROR
            );

            $this->_redirect( 'user' );
        }

        // easiest way to switch users is to just re-autenticate as the new one
        // This maintains consistancy with Zend_Auth and future changes
        $result = $this->_reauthenticate( $nu );

        if( $result->getCode() == Zend_Auth_Result::SUCCESS )
        {
            $this->logger->notice( 'User ' . $this->user['username'] . ' has switched to user '
                . $nu['username'] );

            $this->session->message = new INEX_Message(
                "You are now logged in as {$nu['username']}.", INEX_Message::MESSAGE_TYPE_SUCCESS
            );
        }
        else
        {
            $this->logger->notice( 'User ' . $this->user['username'] . ' has failed to switch to user '
                . $nu['username'] );

            $this->session->message = new INEX_Message(
                "Error: Could not switch user.", INEX_Message::MESSAGE_TYPE_ERROR
            );

            $this->_redirect( 'user' );
        }

        if( $nu['privs'] == User::AUTH_CUSTADMIN )
            $this->_redirect( 'cust-admin/users' );
        else
            $this->_redirect( 'dashboard' );
    }

    /**
     * Switch back to the original user when switched to another.
     *
     * Allows administrators to switch back from another user who they operated as them temporarily.
     */
    public function switchBackAction()
    {
        // are we really operating as another?
        if( !isset( $this->session->switched_user_from ) or !$this->session->switched_user_from )
        {
            $this->session->message = new INEX_Message(
                'You are not currently logged in as another user. You are: ' . $this->user['username'],
                INEX_Message::MESSAGE_TYPE_ERROR
            );

            if( $this->user['privs'] == User::AUTH_SUPERUSER )
            {
                $this->_redirect( 'user' );
            }
            else if( $this->user['privs'] == User::AUTH_CUSTADMIN )
            {
                $this->_redirect( 'cust-admin/users' );
            }
            else
            {
                $this->_redirect( 'dashboard' );
            }
        }

        // does the original user exist
        $ou = Doctrine_Core::getTable( 'User' )->find( $this->session->switched_user_from );

        if( !$ou )
            die( 'The user you are trying to switch back to no longer exists!!' );

        // easiest way to switch users is to just re-autenticate as the new one
        // This maintains consistancy with Zend_Auth and future changes
        $result = $this->_reauthenticate( $ou );

        if( $result->getCode() == Zend_Auth_Result::SUCCESS )
        {
            $this->logger->notice( 'User ' . $ou['username'] . ' has switched back to user '
                . $this->user['username'] );

            $this->session->message = new INEX_Message(
                "You are now logged in as {$ou['username']}.", INEX_Message::MESSAGE_TYPE_SUCCESS
            );
        }
        else
            die( 'Could not switch back!!' );

        unset( $this->session->switched_user_from );
        $this->_redirect( 'user' );
    }

    /**
     * A simple private function to reauthenticate to a given user.
     *
     * @param User $user A Doctrine user object
     */
    private function _reauthenticate( $user )
    {
        $auth = Zend_Auth::getInstance();

        $authAdapter = new INEX_Auth_DoctrineAdapter(
            $user['username'], $user['password']
        );

        return $auth->authenticate( $authAdapter );
    }

}

?>