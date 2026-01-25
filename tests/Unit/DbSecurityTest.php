<?php declare(strict_types=1);

use wlib\Db\Db;
use wlib\Db\Literal;

beforeEach(function ()
{
	$this->db = new Db(Db::DRV_SQLTE, ':memory:');
	$this->db->connect();
	$this->db->execute('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, email TEXT)');
	$this->db->execute('INSERT INTO users (name, email) VALUES (?, ?)', ['test', 'test@example.com']);
});

afterEach(function ()
{
	$this->db->close();
});


test('Literal', function ()
{
	$literal = new Literal('NOW()');

	expect($literal)->toBeInstanceOf(Literal::class);
	expect($literal->getSql())->toBe('NOW()');
});


test('Cache', function ()
{
	expect($this->db->isTable('users'))->toBeTrue();

	$columns = $this->db->getColumns('users');
	expect($columns)->toBeArray();
	expect($columns)->toHaveKey('id');
	expect($columns)->toHaveKey('name');

	$this->db->clearCaches();

	expect(getPrivateProperty($this->db, 'aTableMetadataCache'))->toBeEmpty();
	expect(getPrivateProperty($this->db, 'aTableExistsCache'))->toBeEmpty();
});


test('Escaping', function ()
{
	$query = $this->db->query();

	expect($query->esc('name'))->toBe('"name"');
	expect($query->check('valid_name'))->toBe('valid_name');
	expect(fn() => $query->check('invalid-name'))
		->toThrow(\UnexpectedValueException::class);
});


test('Metadata caching', function ()
{
	// No cache
	$columns1 = $this->db->getColumns('users');

	// With cache
	$columns2 = $this->db->getColumns('users');

	expect($columns1)->toEqual($columns2);

	$idColumn1 = $this->db->getColumns('users', 'id');
	$idColumn2 = $this->db->getColumns('users', 'id');

	expect($idColumn1)->toEqual($idColumn2);
});