<?php

namespace App\Services\Hotel;

use App\Common\FilterException;
use App\Common\SingletonTrait;
use App\Common\Timers;
use App\Common\PDOSingleton;
use App\Entities\HotelEntity;
use App\Entities\RoomEntity;
use App\Services\Room\RoomService;
use Exception;
use PDO;

/**
 * Une classe utilitaire pour récupérer les données des magasins stockés en base de données
 */
class UnoptimizedHotelService extends AbstractHotelService {
  
  use SingletonTrait;
  
  
  protected function __construct () {
    parent::__construct( new RoomService() );
  }
  
  
  /**
   * Récupère une nouvelle instance de connexion à la base de donnée
   *
   * @return PDO
   * @noinspection PhpUnnecessaryLocalVariableInspection
   */
  protected function getDB () : PDO {
    $timer = Timers::getInstance();
    $timerid = $timer->startTimer( 'getDB' ); 
    $pdo = PDOSingleton::getInstance();
    $timer->endTimer( 'getDB', $timerid );
    return $pdo;
  }
  
  
  /**
   * Récupère une méta-donnée de l'instance donnée
   *
   * @param int    $userId
   * @param string $key
   *
   * @return string|null
   */
  protected function getMeta ( int $userId) : ?array {
    $db = $this->getDB();
    $stmt = $db->prepare( "SELECT meta_key,meta_value FROM wp_usermeta WHERE user_id = :userid" );

    $stmt->bindParam( 'userid', $userId, PDO::PARAM_INT );

    $stmt->execute();
    
    $results = $stmt->fetchAll( PDO::FETCH_ASSOC );
    $output = [];

    foreach($results as $result) {
      $output[$result['meta_key']] = $result['meta_value'];
    }
    
    return $output;
  }
  
  
  /**
   * Récupère toutes les meta données de l'instance donnée
   *
   * @param HotelEntity $hotel
   *
   * @return array
   * @noinspection PhpUnnecessaryLocalVariableInspection
   */
  protected function getMetas ( HotelEntity $hotel ) : array {
    $data = $this->getMeta( $hotel->getId() );
    $metaDatas = [
      'address' => [
        'address_1' => $data['address_1'],
        'address_2' => $data['address_2'],
        'address_city' => $data['address_city'],
        'address_zip' => $data['address_zip'],
        'address_country' => $data['address_country'],
      ],
      'geo_lat' =>  $data['geo_lat'],
      'geo_lng' =>  $data['geo_lng'],
      'coverImage' =>  $data['coverImage'],
      'phone' =>  $data['phone'],
    ];
    
    return $metaDatas;
  }
  
  
  /**
   * Récupère les données liées aux évaluations des hotels (nombre d'avis et moyenne des avis)
   *
   * @param HotelEntity $hotel
   *
   * @return array{rating: int, count: int}
   * @noinspection PhpUnnecessaryLocalVariableInspection
   */
  protected function getReviews ( HotelEntity $hotel ) : array {
    // Récupère tous les avis d'un hotel
    $stmt = $this->getDB()->prepare( "SELECT ROUND(AVG(meta_value)) AS rating, COUNT(meta_value) AS count FROM wp_posts, wp_postmeta WHERE wp_posts.post_author = :hotelId AND wp_posts.ID = wp_postmeta.post_id AND meta_key = 'rating' AND post_type = 'review'" );
    $stmt->execute( [ 'hotelId' => $hotel->getId() ] );
    $reviews = $stmt->fetch( PDO::FETCH_ASSOC );

    return $reviews;
  }
  
  
  /**
   * Récupère les données liées à la chambre la moins chère des hotels
   *
   * @param HotelEntity $hotel
   * @param array{
   *   search: string | null,
   *   lat: string | null,
   *   lng: string | null,
   *   price: array{min:float | null, max: float | null},
   *   surface: array{min:int | null, max: int | null},
   *   rooms: int | null,
   *   bathRooms: int | null,
   *   types: string[]
   * }                  $args Une liste de paramètres pour filtrer les résultats
   *
   * @throws FilterException
   * @return RoomEntity
   */
  protected function getCheapestRoom ( HotelEntity $hotel, array $args = [] ) : RoomEntity {

    $argsQuery = [];
    $sqlQuery = "SELECT POST.ID AS id, ".
      "POST.post_title AS title, ".
      "surfaceData.meta_value AS surface, ".
      "MIN(CAST(priceData.meta_value AS UNSIGNED)) AS price, ".
      "roomsData.meta_value AS rooms, ".
      "bathData.meta_value AS bathRooms, ".
      "typeData.meta_value AS types ".
      "FROM wp_posts AS POST";

    $sqlQuery .= " INNER JOIN tp.wp_postmeta AS surfaceData ON POST.ID = surfaceData.post_id AND surfaceData.meta_key = 'surface'";
    if (isset($args['surface']['min'])) {
      $sqlQuery .= " AND surfaceData.meta_value >= :minsurface";
      $argsQuery[] = ['minsurface',$args['surface']['min']];
    }
    if (isset($args['surface']['max'])) {
      $sqlQuery .= " AND surfaceData.meta_value <= :maxsurface";
      $argsQuery[] = ['maxsurface',$args['surface']['max']];
    }

    
    $sqlQuery .= " INNER JOIN tp.wp_postmeta AS priceData ON POST.ID = priceData.post_id AND priceData.meta_key = 'price'";
    if (isset($args['price']['min'])) {
      $sqlQuery .= " AND priceData.meta_value >= :minprice";
      $argsQuery[] = ['minprice',$args['price']['min']];
    }
    if (isset($args['price']['max'])) {
      $sqlQuery .= " AND priceData.meta_value <= :maxprice";
      $argsQuery[] = ['maxprice',$args['price']['max']];
    }

    $sqlQuery .= " INNER JOIN tp.wp_postmeta AS roomsData ON POST.ID = roomsData.post_id AND roomsData.meta_key = 'bedrooms_count'";
    if ( isset($args['rooms'])) {
      $sqlQuery .= " AND roomsData.meta_value >= :rooms";
      $argsQuery[] = ['rooms',$args['rooms']];
    }

    $sqlQuery .= " INNER JOIN tp.wp_postmeta AS bathData ON POST.ID = bathData.post_id AND bathData.meta_key = 'bathrooms_count'";
    if ( isset($args['bathRooms'])) {
      $sqlQuery .= " AND bathData.meta_value >= :bathrooms";
      $argsQuery[] = ['bathrooms',$args['bathRooms']];
    }

    $sqlQuery .= " INNER JOIN tp.wp_postmeta AS typeData ON POST.ID = typeData.post_id AND typeData.meta_key = 'type'";
    if ( !empty($args['types']) ) {
      $sqlQuery .= ' AND typeData.meta_value IN ("'. implode('","', $args['types']) .'")';
    }

    $sqlQuery .= " WHERE post_author = :hotelId AND post_type = 'room'";

    $sqlQuery .= " GROUP BY POST.ID ORDER BY price ASC LIMIT 1";

    $stmt = $this->getDB()->prepare($sqlQuery);

    foreach ($argsQuery as $arg) {
      $stmt->bindParam(':'.$arg[0],$arg[1]);
    }

    $stmt->bindValue(':hotelId',$hotel->getId());

    $stmt->execute();
    $result = $stmt->fetch();
    // Si aucune chambre ne correspond aux critères, alors on déclenche une exception pour retirer l'hôtel des résultats finaux de la méthode list().
    if ( !$result )
      throw new FilterException( "Aucune chambre ne correspond aux critères" );

    $cheapestRoom = (new RoomEntity())
    ->setId($result['id'])
    ->setTitle($result['title'])
    ->setSurface($result['surface'])
    ->setPrice($result['price'])
    ->setBedRoomsCount($result['rooms'])
    ->setBathRoomsCount($result['bathRooms'])
    ->setType($result['types']);
    
    return $cheapestRoom;
  }
  
  
  /**
   * Calcule la distance entre deux coordonnées GPS
   *
   * @param $latitudeFrom
   * @param $longitudeFrom
   * @param $latitudeTo
   * @param $longitudeTo
   *
   * @return float|int
   */
  protected function computeDistance ( $latitudeFrom, $longitudeFrom, $latitudeTo, $longitudeTo ) : float|int {
    return ( 111.111 * rad2deg( acos( min( 1.0, cos( deg2rad( $latitudeTo ) )
          * cos( deg2rad( $latitudeFrom ) )
          * cos( deg2rad( $longitudeTo - $longitudeFrom ) )
          + sin( deg2rad( $latitudeTo ) )
          * sin( deg2rad( $latitudeFrom ) ) ) ) ) );
  }
  
  
  /**
   * Construit une ShopEntity depuis un tableau associatif de données
   *
   * @throws Exception
   */
  protected function convertEntityFromArray ( array $data, array $args ) : HotelEntity {
    $hotel = ( new HotelEntity() )
      ->setId( $data['ID'] )
      ->setName( $data['display_name'] );

    $timer = Timers::getInstance();
    
    // Charge les données meta de l'hôtel
    $timerid = $timer->startTimer( 'getMetas' ); 
    $metasData = $this->getMetas( $hotel );
    $timer->endTimer('getMetas', $timerid);
    $hotel->setAddress( $metasData['address'] );
    $hotel->setGeoLat( $metasData['geo_lat'] );
    $hotel->setGeoLng( $metasData['geo_lng'] );
    $hotel->setImageUrl( $metasData['coverImage'] );
    $hotel->setPhone( $metasData['phone'] );
    
    // Définit la note moyenne et le nombre d'avis de l'hôtel
    $timerid = $timer->startTimer( 'getReviews' ); 
    $reviewsData = $this->getReviews( $hotel );
    $timer->endTimer('getReviews', $timerid);

    $hotel->setRating( $reviewsData['rating'] );
    $hotel->setRatingCount( $reviewsData['count'] );
    
    // Charge la chambre la moins chère de l'hôtel
    $timerid = $timer->startTimer( 'getCheapest' );
    $cheapestRoom = $this->getCheapestRoom( $hotel, $args );
    $timer->endTimer('getCheapest', $timerid);
    $hotel->setCheapestRoom($cheapestRoom);
    
    // Verification de la distance
    if ( isset( $args['lat'] ) && isset( $args['lng'] ) && isset( $args['distance'] ) ) {
      $hotel->setDistance( $this->computeDistance(
        floatval( $args['lat'] ),
        floatval( $args['lng'] ),
        floatval( $hotel->getGeoLat() ),
        floatval( $hotel->getGeoLng() )
      ) );
      
      if ( $hotel->getDistance() > $args['distance'] )
        throw new FilterException( "L'hôtel est en dehors du rayon de recherche" );
    }
    
    return $hotel;
  }
  
  
  /**
   * Retourne une liste de boutiques qui peuvent être filtrées en fonction des paramètres donnés à $args
   *
   * @param array{
   *   search: string | null,
   *   lat: string | null,
   *   lng: string | null,
   *   price: array{min:float | null, max: float | null},
   *   surface: array{min:int | null, max: int | null},
   *   bedrooms: int | null,
   *   bathrooms: int | null,
   *   types: string[]
   * } $args Une liste de paramètres pour filtrer les résultats
   *
   * @throws Exception
   * @return HotelEntity[] La liste des boutiques qui correspondent aux paramètres donnés à args
   */
  public function list ( array $args = [] ) : array {
    $db = $this->getDB();
    $stmt = $db->prepare( "SELECT * FROM wp_users" );
    $stmt->execute();
    
    $results = [];
    foreach ( $stmt->fetchAll( PDO::FETCH_ASSOC ) as $row ) {
      try {
        $results[] = $this->convertEntityFromArray( $row, $args );
      } catch ( FilterException ) {
        // Des FilterException peuvent être déclenchées pour exclure certains hotels des résultats
      }
    }
    
    
    return $results;
  }
}