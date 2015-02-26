<?php // with ♥ and Contao

/**
 * anyStores for Contao Open Source CMS
 *
 * @copyright   (c) 2014, 2015 Tastaturberuf <mail@tastaturberuf.de>
 * @author      Daniel Jahnsmüller <mail@jahnsmueller.net>
 * @license     http://opensource.org/licenses/lgpl-3.0.html
 * @package     anyStores
 */


namespace Tastaturberuf;


class AnyStoresModel extends \Model
{

    /**
     * Table name
     * @var string
     */
    protected static $strTable = 'tl_anystores';

    /**
     * Details loaded
     * @var boolean
     */
    protected $blnDetailsLoaded = false;


    /**
     * Find one published store by id or alias
     *
     * @param mixed $varId The numeric ID or alias name
     * @param array $arrOptions An optional options array
     * @return \Model|null The model or null if there is no store
     */
    public static function findPublishedByIdOrAlias($varId, array $arrOptions=array())
    {
        $t = static::$strTable;

        $arrColumns   = is_numeric($varId) ? array("$t.id=?") : array("$t.alias=?");
        $arrColumns[] = "($t.start='' OR $t.start<UNIX_TIMESTAMP()) AND ($t.stop='' OR $t.stop>UNIX_TIMESTAMP()) AND $t.published=1";

        return static::findOneBy($arrColumns, $varId, $arrOptions);
    }


    /**
     * Find multiple by category
     * @param array $arrCategories Array of IDs
     * @param array $arrOptions An optional options array
     * @return \Model\Collection|null The model collection or null if the result is empty
     */
    public static function findPublishedByCategory(array $arrCategories, array $arrOptions=array())
    {
        $t = static::$strTable;

        $arrColumns   = array("$t.pid IN(".implode(',', array_map('intval', $arrCategories)).")");
        $arrColumns[] = "($t.start='' OR $t.start<UNIX_TIMESTAMP()) AND ($t.stop='' OR $t.stop>UNIX_TIMESTAMP()) AND $t.published=1";

        return static::findBy($arrColumns, $arrOptions);
    }


    /**
     * Find multiple by category and country
     * @param array $arrCategories Array of IDs
     * @param string $strCountry Countrycode like 'de'
     * @param array $arrOptions An optional options array
     * @return \Model\Collection|null The model collection or null if the result is empty
     */
    public static function findPublishedByCategoryAndCountry(array $arrCategories, $strCountry, array $arrOptions=array())
    {
        $t = static::$strTable;

        $arrColumns   = array("$t.pid IN(".implode(',', array_map('intval', $arrCategories)).")");
        $arrColumns[] = "$t.country=?";
        $arrColumns[] = "($t.start='' OR $t.start<UNIX_TIMESTAMP()) AND ($t.stop='' OR $t.stop>UNIX_TIMESTAMP()) AND $t.published=1";

        return static::findBy($arrColumns, $strCountry, $arrOptions);
    }


    public static function findPublishedByAdressAndCountryAndCategory(
        $strSearch, $strCountry, array $arrCategories, $intLimit=null, $intMaxDistance=null)
    {
        $t = static::$strTable;

        $arrCoordinates = AnyStores::getLonLat($strSearch, $strCountry);

        if ( !$arrCoordinates )
        {
            //@todo log
            return;
        }

        $arrOptions = array
        (
            'fields' => array
            (
                "$t.id",
                "( 6371 * acos( cos( radians(?) ) * cos( radians( $t.latitude ) ) * cos( radians( $t.longitude ) - radians(?) ) + sin( radians(?) ) * sin( radians( $t.latitude ) ) ) ) AS distance"
            ),
            'table'  => $t,
            'column' => array
            (
                // Categories
                "$t.pid IN(".implode(',', array_map('intval', $arrCategories)).")",
                // Country
                "$t.country=?",
                // Published
                "($t.start='' OR $t.start<UNIX_TIMESTAMP()) AND ($t.stop='' OR $t.stop>UNIX_TIMESTAMP()) AND $t.published=1"
            ),
            'order' => "distance"
        );

        // Maximun distance
        if ( is_numeric($intMaxDistance) )
        {
            $arrOptions['having'] = "distance < ".$intMaxDistance;
        }

        // Get query
        $strQuery = \Model\QueryBuilder::find($arrOptions);

        // replace * with additional fields
        $strQuery = preg_replace('/\*/', implode(',', $arrOptions['fields']), $strQuery, 1);

        $objResult = \Database::getInstance()
            ->prepare($strQuery)
            ->limit($intLimit)
            ->execute(
                $arrCoordinates['latitude'],
                $arrCoordinates['longitude'],
                $arrCoordinates['latitude'],
                $strCountry
            );

        if ( !$objResult->numRows )
        {
            //@todo log
            return;
        }

        // Create store models from database result
        while ( $objResult->next() )
        {
            $objModel = AnyStoresModel::findByPk($objResult->id);
            $objModel->distance = $objResult->distance;
            $objModel->preventSaving();

            $arrModels[] = $objModel;
        }

        // Return model collection
        return new \Model\Collection($arrModels, 'tl_anystores');
    }


    /**
     * Get the details from a store
     * @return \AnyStoresModel
     */
    public function loadDetails()
    {
        //load country names
        //@todo load only once. not every time.
        $arrCountryNames = \System::getCountries();

        //full localized country name
        //@todo rename country to countrycode in database
        $this->countrycode = $this->country;
        $this->country     = $arrCountryNames[$this->countrycode];

        // opening times
        $this->opening_times = deserialize($this->opening_times);

        // store logo
        //@todo change size and properties in module
        if ( $this->logo )
        {
            if ( ($objLogo = \FilesModel::findByUuid($this->logo)) !== null )
            {
                $arrLogo = $objLogo->row();
                $arrMeta = deserialize($arrLogo['meta']);
                $arrLogo['meta'] = $arrMeta[$GLOBALS['TL_LANGUAGE']];

                $this->logo = $arrLogo;
            }
            else
            {
                $this->logo = null;
            }
        }

        // Cut distance
        if ( $this->distance )
        {
            $this->distance = number_format($this->distance, 2, ',', '.');
        }

        // Prevent from saving
        $this->preventSaving();
        $this->blnDetailsLoaded = true;

        return $this;
    }
}
