<?php 
/*
Copyright 2014 Domabo

Wrapper for Facebook Profile Picture

Using a facebook ID finds out the URL of the profile picture and downloads it to th temp folder. 
Returns the file name created.

No Facebook App or API key is required

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

class amrFacebook
{
  public static function get_fb_img($fbId)
  {
    try
    {
      $url = 'http://graph.facebook.com/' . $fbId . '/picture?type=large';
      $headers = get_headers($url,1);

      $profileimage = $headers['Location']; 

      $ext = pathinfo($profileimage, PATHINFO_EXTENSION);
      $filename = sys_get_temp_dir() . "/" . $fbId . "." . $ext;

      if (file_exists($filename)) 
      {
        return $filename;
      } else 
      {

        $ch = curl_init($profileimage);
        $fp = fopen( $filename, "wb");
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_USERAGENT,"Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US) AppleWebKit/525.13 (KHTML, wie z. B. Gecko) Chrome/13.0.782.215 Safari/525.13." );
        curl_exec($ch);
        curl_close($ch);
        fclose($fp);
        return $filename;
      }
    }  catch (Exception $e) {
      return null;
    }
  }
}
?>