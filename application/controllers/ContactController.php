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
 * Controller: Manage contacts
 *
 * @author     Barry O'Donovan <barry@opensolutions.ie>
 * @category   INEX
 * @package    INEX_Controller
 * @copyright  Copyright (c) 2009 - 2012, Internet Neutral Exchange Association Ltd
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class ContactController extends INEX_Controller_FrontEnd
{
    
    /**
     * This function sets up the frontend controller
     */
    protected function _feInit()
    {
        $this->assertPrivilege( \Entities\User::AUTH_SUPERUSER );
        
        $this->view->feParams = $this->_feParams = (object)[
            'entity'        => '\\Entities\\Contact',
            'form'          => 'INEX_Form_Contact',
            'pagetitle'     => 'Contacts',
        
            'titleSingular' => 'Contact',
            'nameSingular'  => 'a contact',
        
            'defaultAction' => 'list',                    // OPTIONAL; defaults to 'list'
        
            'listOrderBy'    => 'name',
            'listOrderByDir' => 'ASC',
    
            'listColumns'    => [
            
                'id'        => [ 'title' => 'UID', 'display' => false ],
    
                'customer'  => [
                    'title'      => 'Customer',
                    'type'       => self::$FE_COL_TYPES[ 'HAS_ONE' ],
                    'controller' => 'customer',
                    'action'     => 'overview',
                    'idField'    => 'custid'
                ],
    
                'name'      => 'Name',
                'email'     => 'Email',
                'phone'     => 'Phone',
                'mobile'    => 'Mobile'
            ]
        ];
    
        // display the same information in the view as the list
        $this->_feParams->viewColumns = array_merge(
            $this->_feParams->listColumns,
            [
                'facilityaccess' => 'Facility Access',
                'mayauthorize'   => 'May Authorize',
                'lastupdated'    => [
                    'title'         => 'Last Updated',
                    'type'          => self::$FE_COL_TYPES[ 'DATETIME' ]
                ],
                'lastupdatedby'  => 'Last Updated By',
                'creator'        => 'Creator',
                'created'        => [
                    'title'         => 'Created',
                    'type'          => self::$FE_COL_TYPES[ 'DATETIME' ]
                ]
            ]
        );
    }


    /**
     * Provide array of users for the listAction and viewAction
     *
     * @param int $id The `id` of the row to load for `viewAction`. `null` if `listAction`
     */
    protected function listGetData( $id = null )
    {
        $qb = $this->getD2EM()->createQueryBuilder()
        ->select( 'c.id as id, c.name as name, c.email as email, c.phone AS phone, c.mobile AS mobile,
                c.facilityaccess AS facilityaccess, c.mayauthorize AS mayauthorize,
                c.lastupdated AS lastupdated, c.lastupdatedby AS lastupdatedby,
                c.creator AS creator, c.created AS created, cust.name AS customer, cust.id AS custid'
            )
        ->from( '\\Entities\\Contact', 'c' )
        ->leftJoin( 'c.Customer', 'cust' );
    
        if( isset( $this->_feParams->listOrderBy ) )
            $qb->orderBy( $this->_feParams->listOrderBy, isset( $this->_feParams->listOrderByDir ) ? $this->_feParams->listOrderByDir : 'ASC' );
    
        if( $id !== null )
            $qb->andWhere( 'c.id = ?1' )->setParameter( 1, $id );
    
        return $qb->getQuery()->getResult();
    }
    
    
    /**
     *
     * @param INEX_Form_Contact $form The form object
     * @param \Entities\Contact $object The Doctrine2 entity (being edited or blank for add)
     * @param bool $isEdit True of we are editing an object, false otherwise
     * @param array $options Options passed onto Zend_Form
     * @param string $cancelLocation Where to redirect to if 'Cancal' is clicked
     * @return void
     */
    protected function formPostProcess( $form, $object, $isEdit, $options = null, $cancelLocation = null )
    {
        if( $isEdit )
            $form->getElement( 'custid' )->setValue( $object->getCustomer()->getId() );
    }
    
    
    /**
     *
     * @param INEX_Form_Contact $form The form object
     * @param \Entities\Contact $object The Doctrine2 entity (being edited or blank for add)
     * @param bool $isEdit True of we are editing an object, false otherwise
     * @return void
     */
    protected function addPostValidate( $form, $object, $isEdit )
    {
        $object->setCustomer(
            $this->getD2EM()->getRepository( '\\Entities\\Customer' )->find( $form->getElement( 'custid' )->getValue() )
        );
    
        if( $isEdit )
        {
            $object->setLastupdated( new DateTime() );
            $object->setLastupdatedby( $this->getUser()->getId() );
        }
        else
        {
            $object->setCreated( new DateTime() );
            $object->setCreator( $this->getUser()->getUsername() );
        }
    
        return true;
    }
    
    
}
