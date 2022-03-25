<?php

/* This file is part of Jeedom.
*
* Jeedom is free software: you can redistribute it and/or modify
* it under the terms of the GNU General Public License as published by
* the Free Software Foundation, either version 3 of the License, or
* (at your option) any later version.
*
* Jeedom is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
* GNU General Public License for more details.
*
* You should have received a copy of the GNU General Public License
* along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
*/

/* * ***************************Includes********************************* */


require_once __DIR__  . '/../../../../core/php/core.inc.php';
//require_once 'prixcarburants.class.php';

class pxc_station 
{
  const URL_GMAP = 'https://www.google.com/maps/dir/?api=1&travelmode=driving&dir_action=navigate&origin=';
  const	URL_WAZE = 'https://waze.com/ul?';
  
  private $reader;
  public $id;
  public $lat;
  public $lng; 
  public $stationDep;
  private $ville;
  private $marque;
  public $prix;
  private $cp;
  private $dist;
  private $maj;
  private $waze;
  private $gmap;
  
  function __construct($readerC) {
    	
        $this->reader = $readerC;
    	$this->lat = $this->reader->getAttribute('latitude') / 100000;
		$this->lng = $this->reader->getAttribute('longitude') / 100000;
    	$this->stationDep = intval(substr($this->reader->getAttribute('cp'), 0, 2));
    	$this->cp = $this->reader->getAttribute('cp');
    	$this->id = $this->reader->getAttribute('id');
    	
    }
	/** launch a global computation on value extracted from xml */
  public function computeAllDatas($typecarburant, $latD, $lngD, $monformatdate){
    	$this->dist = $this->distance($latD, $lngD);  
    	$this->marque = self::getMarqueStation($this->id, $this->stationDep);
    	
    	$this->maj = prixcarburants::TranslateDate($monformatdate, config::byKey('language'), strtotime($this->prix['maj']));
    	$this->waze = self::URL_WAZE . 'to=ll.' . urlencode($this->lat . ',' . $this->lng) . '&from=ll.' . urlencode($latD . ',' . $lngD) . '&navigate=yes';
    	$this->gmap = self::URL_GMAP . urlencode($this->lat . ',' . $this->lng) . '&destination=' . urlencode($latD . ',' . $lngD);
    	if(is_null($this->prix))caculatePrice($typecarburant);
  	}
	  /** to calculate price maj and other values linked */
  public function caculatePrice($typecarburant){
		$doc = new DOMDocument;
		$unestation = simplexml_import_dom($doc->importNode($this->reader->expand(), true));
		$this->prix = $this->getPriceFromStationXML($unestation, $typecarburant);
		$this->ville = $unestation->ville;
		$this->adresse = $unestation->adresse;
		unset($doc);
	}

	/** return the array with all datas, should call setPriceAndCity and computeAllDatas before */
  public function getDescArray(){
		$descArr = array(
			'adresse' => $this->getAdresse(),
			'adressecompl' => $this->getFullAdresse(),
			'id' => $this->id,
			'coord' => $this->lat . "," . $this->lng,
			'logo' => $this->getLogoPath(),
			'maj' => $this->maj,
			'distance' => $this->dist,
			'googleMap'=> $this->gmap,
			'waze' => $this->waze,
			'prix' => $this->prix['prix']
		);
		
		return $descArr;
	}
	/** calculate the distance between lng lat from station xml to lat lng in argument */
  public function distance($latD, $lngD){
    	if(is_null($this->lat) || is_null($this->lng) ||is_null($latD) ||is_null($lngD)){
          $this->dist = 0;
        }elseif(is_null($this->dist)){
          $this->dist =self::distanceToStation($this->lat, $this->lng, $latD, $lngD);
        }
    	return $this->dist;
  	}
/** return formated logo path */
  public function getLogoPath(){
  		$LogoName = strtoupper(str_replace(' ', '', $this->marque));
    	$logo =file_exists(prixcarburants::ZIP_PATH . '/logo/' . $LogoName . '.png')?prixcarburants::PATH_TO_LOGO . $LogoName . '.png':prixcarburants::PATH_TO_LOGO . 'AUCUNE.png';
    	return $logo;
  	}
/** return formatted adresse */
  public function getAdresse(){
  		return $this->marque . ', ' . $this->ville;
  	}
/** return formatted full adresse */
  public function getFullAdresse(){
  		return $this->adresse . ", " . $this->cp . ' ' . $this->ville;
  	}
 
  /** Function to calculate a distance between selected location and a station */
	public static function distanceToStation($lat1, $lng1, $lat2, $lng2, $unit = 'k')
	{
		$earth_radius = 6378137;	// Terre = sphère de 6378km de rayon
		$rlo1 = deg2rad($lng1);
		$rla1 = deg2rad($lat1);
		$rlo2 = deg2rad($lng2);
		$rla2 = deg2rad($lat2);
		$dlo = ($rlo2 - $rlo1) / 2;
		$dla = ($rla2 - $rla1) / 2;
		$a = (sin($dla) * sin($dla)) + cos($rla1) * cos($rla2) * (sin($dlo) * sin($dlo));
		$d = 2 * atan2(sqrt($a), sqrt(1 - $a));
		return round(($earth_radius * $d) / 1000);
	}
  	/** Function to get the brand of a fuel station */
	public static function getMarqueStation($idstation, $DepStation)
	{
		$json = @file_get_contents(prixcarburants::ZIP_PATH . '/listestations/stations' . $DepStation . '.json');
		if ($json !== false) {
			//log::add(__CLASS__, 'debug', 'JSON file : ' . self::ZIP_PATH . '/data/listestations/stations' . $DepStation . '.json available');
			$parsed_json = json_decode($json, true);
			foreach ($parsed_json['stations'] as $row) {
				if ($row['id'] == $idstation) {
					return $row['marque'];
					break;
				}
			}
		} else {
			log::add('prixcarburants', 'debug', 'JSON file : ' . prixcarburants::ZIP_PATH . '/listestations/stations' . $DepStation . '.json not available');
			return __('Erreur', __FILE__);
		}
	}
  	/** getPriceFromStationXML : allow to get an array with keys : 'prix' for price corresponding at $typecarburant and 'maj' for update date corresponding
    * $unestation : xml node extracted
    * $typecarburant : the tyep of carburant 
    * return false if carburant not found in the list
    */
  	public static function getPriceFromStationXML($unestation, $typecarburant){
      foreach ($unestation->prix as $prix) {
        if ($prix->attributes()->nom == $typecarburant) { //Filter by fuel type
         
          $prixlitre = $prix->attributes()->valeur . '';
          $maj = $prix->attributes()->maj . '';
           return array('prix'=>$prixlitre, 'maj'=>$maj);
        }
      }
      return false;
    }
}
