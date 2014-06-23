<?php
/*
Copyright 2014 Domabo

Wrapper for Podio API

Creates items that have associated contact records.   If a facebook ID is supplied, downloads the Facebook profile picture
and places in the Podio avatar field.  If the contact already exists on Podio, simply updates it.

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA 02111-1307 USA
*/

if(!class_exists("amrFacebook"))
     require_once("amrFacebook.php");

require_once("api-podio/PodioAPI.php");

class amrPodio
{

  public static function setup($PODIO_CLIENTID, $PODIO_CLIENTSECRET)
  {
    Podio::setup($PODIO_CLIENTID, $PODIO_CLIENTSECRET);
  }

  public static function authenticate( $PODIO_APPID, $PODIO_APPTOKEN)
  {
    try
    {
      Podio::authenticate_with_app($PODIO_APPID, $PODIO_APPTOKEN);
      return true;
    } catch (PodioError $e) 
    {
      return false;
    }
  }

  public static function getApp($appid, $apptoken)
  {

    if (!Podio::is_authenticated())
    {
      self::authenticate($appid, $apptoken);
    }
    try
    {
      return PodioApp::get( $appid, $attributes = array() );
    } catch (PodioError $e) 
    {
      return null;
    }
  }

  public static function createContactItem($appid, $spaceid, $contact_name, $contact_email, $contact_facebook, &$item_fields, $contact_target_tag)
  {
    try
    {

      if (!empty($contact_target_tag))
      {
        $contact_fields_index = array("name"=>$contact_name, "mail"=>array($contact_email));
        $contact_fields = $contact_fields_index;

        if (!empty($contact_facebook))
        {
          $filename = amrFacebook::get_fb_img($contact_facebook);
          if ($filename)
          {
            $fid = PodioFile::upload ($filename, $contact_facebook . ".jpg");
            $contact_fields["avatar"] = ($fid->file_id);
          }
        }

        $existingContacts = PodioContact::get_for_app( $appid, $attributes = $contact_fields_index);

        if (count($existingContacts)>0)
        {
          $first =  $existingContacts[0];
          $ep_profile_id = $first->profile_id;

          PodioContact::update( $ep_profile_id, $contact_fields );

        } else
        {
          $ep_profile_id = PodioContact::create( $spaceid, $contact_fields);
        }
             $item_fields[$contact_target_tag] = $ep_profile_id;
      }
     
      PodioItem::create( $appid,  array('fields' => $item_fields));
      return null;

    } catch (PodioError $e) {

      return self::createErrorTask($appid, 
        $spaceid, 
        $contact_name, 
        "There was an error. Podio responded with the error type " . $e->body['error'] ." and the mesage " . $e->body['error_description'] . "."
        );

    } catch (Exception $e) {

      return self::createErrorTask($appid, 
        $spaceid, 
        $contact_name, 
        "There was a general exception: " . $$e->getMessage()
        );
    }
  }

  public static function createErrorTask($appid, $spaceid, $contact_name, $description)
  {
    $title = "API Error creating Podio Item";

    if (!empty($contact_name))
      $title = $title . " for " . $contact_name;

    $err = self::createTask($appid, $spaceid, $title, $description);

    if ($err)
      $description = $description . "     " . $err;

    return $description;
  }

  public static function createTask($appid, $spaceid, $title, $description)
  {

    if (!Podio::is_authenticated())
       return "No task created as not authenticated";

    try  
    {
      $task = PodioTask::create_for( "app", $appid, $attributes = array( "text" => $title,
        "private" => false,
        "description" => $description,
        "status" => "active",
        "space_id" => $spaceid), $options = array() );

      return null;
    }  
    catch (PodioError $te) 
    {
      return "There was an error. The API responded with the error type " . $te->body['error'] ." and the mesage " . $te->body['error_description'] . ".";
    }
  }
}
?>