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

/**
 * Controller: User profile
 *
 * @author     Barry O'Donovan <barry@opensolutions.ie>
 * @category   INEX
 * @package    INEX_Controller
 * @copyright  Copyright (c) 2009 - 2012, Internet Neutral Exchange Association Ltd
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */

class ProfileController extends INEX_Controller_AuthRequiredAction
{
    use OSS_Controller_Trait_Profile;
    
    /**
     * Users mailing list subs as set via init() -> _initMailingListSubs()
     *
     * @var array
     */
    protected $_mailinglists;
    

    /**
     * Return the appropriate change password form for your application
     */
    protected function _getFormChangePassword()
    {
        return new INEX_Form_ChangePassword();
    }
    
    /**
     * Return the appropriate change profile form for your application
     */
    protected function _getFormProfile()
    {
        $pf = new INEX_Form_Profile();
        
        $pf->getElement( 'username' )->setValue( $this->getUser()->getUsername() );
        $pf->getElement( 'mobile'   )->setValue( $this->getUser()->getAuthorisedMobile() );
        $pf->getElement( 'email'    )->setValue( $this->getUser()->getEmail() );
        
        return $pf;
    }
    
    
    
    public function init()
    {
        $this->_initMailingListSubs();
    }

    public function indexAction()
    {
        if( !isset( $this->view->profileForm ) )
            $this->view->profileForm = $this->_getFormProfile();
        
        if( !isset( $this->view->passwordForm ) )
            $this->view->passwordForm = $this->_getFormChangePassword();
    }

    protected function changePasswordPostFlush()
    {
        $this->clearUserFromCache();
    }
    
    /**
     * Action to allow a user to change their profile
     *
     */
    public function changeProfileAction()
    {
        $this->view->profileForm = $form = $this->_getFormProfile();
        
        if( $this->getRequest()->isPost() && $form->isValid( $_POST ) )
        {
            // update the users profile
            $this->getUser()->setAuthorisedMobile( $form->getValue( 'mobile' ) );
            $this->getUser()->setLastUpdated( new DateTime() );
            $this->getUser()->setLastUpdatedBy( $this->getUser()->getId() );
            $this->getD2EM()->flush();
            $this->clearUserFromCache();
            
            $this->getLogger()->info( "User {$this->getUser()->getUsername()} updated own profile" );
            $this->addMessage( _( 'Your profile has been changed.' ), OSS_Message::SUCCESS );
            $this->redirect( 'profile/index' );
        }
    
        $this->forward( 'index' );
    }
    

    
    public function updateMailingListsAction()
    {
        // need to capture all users with the given email
        $users = $this->getD2EM()->getRepository( '\\Entities\\User' )->findBy( [ 'email' => $this->getUser()->getEmail() ] );
        
        foreach( $this->_options['mailinglists'] as $name => $ml )
        {
            if( isset( $_POST["ml_{$name}"] ) && $_POST["ml_{$name}"] )
                foreach( $users as $u )
                    $u->setPreference( "mailinglist.{$name}.subscribed", 1 );
            else
                foreach( $users as $u )
                    $u->setPreference( "mailinglist.{$name}.subscribed", 0 );
        }
        
        $this->getD2EM()->flush();
        $this->addMessage( 'Your mailing list subscriptions have been updated and will take effect within 12 hours.', OSS_Message::SUCCESS );
        $this->redirect( 'profile/index' );
    }
    
    private function _initMailingListSubs()
    {
        // are we using mailing lists?
        if( !isset( $this->_options['mailinglist']['enabled'] ) || !$this->_options['mailinglist']['enabled'] )
            return;
        
        $mlsubs = [];
        
        foreach( $this->_options['mailinglists'] as $name => $ml )
            $mlsubs[$name] = $this->getUser()->getPreference( "mailinglist.{$name}.subscribed" );
        
        $this->view->mlsubs = $mlsubs;
    }
}

