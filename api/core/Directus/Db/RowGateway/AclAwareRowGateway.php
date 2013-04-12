<?php

namespace Directus\Db\RowGateway;

use Directus\Bootstrap;
use Directus\Acl\Acl;
use Directus\Acl\Exception\UnauthorizedTableAddException;
use Directus\Acl\Exception\UnauthorizedFieldReadException;
use Directus\Acl\Exception\UnauthorizedFieldWriteException;
use Zend\Db\Adapter\Exception\InvalidQueryException;
use Zend\Db\RowGateway\RowGateway;

class AclAwareRowGateway extends RowGateway {

    protected $aclProvider;

    /**
     * Constructor
     * @param AclProvider $aclProvider
     * @param string $primaryKeyColumn
     * @param string|\Zend\Db\Sql\TableIdentifier $table
     * @param Adapter|Sql $adapterOrSql
     * @throws Exception\InvalidArgumentException
     */
    public function __construct(Acl $aclProvider, $primaryKeyColumn, $table, $adapterOrSql) {
        $this->aclProvider = $aclProvider;
        parent::__construct($primaryKeyColumn, $table, $adapterOrSql);
    }

    /**
     * HELPER FUNCTIONS
     */

    public static function makeRowGatewayFromTableName($aclProvider, $table, $adapter, $pkFieldName = 'id') {
        // Underscore to camelcase table name to namespaced row gateway classname,
        // e.g. directus_users => \Directus\Db\RowGateway\DirectusUsersRowGateway
        $rowGatewayClassName = underscoreToCamelCase($table) . "RowGateway";
        $rowGatewayClassName = __NAMESPACE__ . "\\$rowGatewayClassName";
        if(class_exists($rowGatewayClassName))
            return new $rowGatewayClassName($aclProvider, $pkFieldName, $table, $adapter);
        return new self($aclProvider, $pkFieldName, $table, $adapter);
    }

    // as opposed to toArray()
    // used only for proof of concept
    public function __getUncensoredDataForTesting() {
        return $this->data;
    }

    public function logger() {
        return Bootstrap::get('app')->getLog();
    }

    /**
     * ONLY USE THIS FOR INITIALIZING THE ROW OBJECT.
     *
     * This function does not enforce ACL write privileges.
     * It shouldn't be used to fulfill data assignment on behalf of the user.
     *
     * @param  mixed  $rowData Row key/value pairs.
     * @return AclAwareRowGateway
     */
    public function populateSkipAcl($rowData, $rowExistsInDatabase = false) {
        $this->initialize();
        $this->data = $rowData;
        if ($rowExistsInDatabase == true) {
            $this->processPrimaryKeyData();
        } else {
            $this->primaryKeyData = null;
        }
        return $this;
    }

    /**
     * OVERRIDES
     */

    /**
     * ONLY USE THIS FOR INITIALIZING THE ROW OBJECT.
     *
     * This function does not enforce ACL write privileges.
     * It shouldn't be used to fulfill data assignment on behalf of the user.
     * @param  mixed $rowData Row key/value pairs.
     * @return AclAwareRowGateway
     */
    public function exchangeArray($rowData) {
        return $this->populateSkipAcl($rowData, true);
    }

    public function save() {
        $this->initialize();
        // Enforce Privilege: Table Add
        if(!$this->rowExistsInDatabase() && !$this->aclProvider->hasTablePrivilege($this->table, 'add'))
            throw new UnauthorizedTableAddException("Table add access forbidden on table " . $this->table);
        try {
            return parent::save();
        } catch(InvalidQueryException $e) {
            $this->logger()->fatal("Error running save on this data: " . print_r($this->data, true));
            throw $e;
        }
    }

    /**
     * Populate Data
     *
     * @param  array $rowData
     * @param  bool  $rowExistsInDatabase
     * @return AclAwareRowGateway
     */
    public function populate(array $rowData, $rowExistsInDatabase = false)
    {
        // Enforce field write blacklist
        $attemptOffsets = array_keys($rowData);
        $this->aclProvider->enforceBlacklist($this->table, $attemptOffsets, Acl::FIELD_WRITE_BLACKLIST);
        return parent::populate($rowData, $rowExistsInDatabase);
    }

    /**
     * Offset Exists
     *
     * @param  string $offset
     * @return bool
     */
    public function offsetExists($offset)
    {
        // Filter censored fields
        $censoredData = $this->toArray();
        return array_key_exists($offset, $censoredData);
    }

    /**
     * @return int
     */
    public function count()
    {
        // Don't include censored fields in the field count
        $censoredData = $this->toArray();
        return count($censoredData);
    }

    /**
     * __get
     *
     * @param  string $name
     * @return mixed
     */
    public function __get($name)
    {
        // Confirm user group has read privileges on field with name $name
        $this->aclProvider->enforceBlacklist($this->table, $name, ACL::FIELD_READ_BLACKLIST);
        return parent::__get($name);
    }

    /**
     * Offset get
     *
     * @param  string $offset
     * @return mixed
     */
    public function offsetGet($offset)
    {
        // Confirm user group has read privileges on field with name $name
        $this->aclProvider->enforceBlacklist($this->table, $offset, ACL::FIELD_READ_BLACKLIST);
        return parent::offsetGet($offset);
    }

    /**
     * Offset set
     *
     * NOTE: Protecting this method protects self#__set, which calls this method in turn.
     *
     * @param  string $offset
     * @param  mixed $value
     * @return AclAwareRowGateway
     */
    public function offsetSet($offset, $value)
    {
        // Enforce field write blacklist
        $this->aclProvider->enforceBlacklist($this->table, $offset, Acl::FIELD_WRITE_BLACKLIST);
        return parent::offsetSet($offset, $value);
    }

    /**
     * Offset unset
     *
     * @param  string $offset
     * @return AclAwareRowGateway
     */
    public function offsetUnset($offset)
    {
        // Enforce field write blacklist
        $this->aclProvider->enforceBlacklist($this->table, $offset, Acl::FIELD_WRITE_BLACKLIST);
        return parent::offsetUnset($offset);
    }

    /**
     * To array
     *
     * @return array
     */
    public function toArray()
    {
        // Respect the read blacklist
        $data = $this->aclProvider->censorFields($this->table, $this->data);
        return $data;
    }

}