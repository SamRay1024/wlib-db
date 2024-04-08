<?php

use wlib\Db\Db;
use wlib\Db\Table;

$dbfile = __DIR__ .'/tmp/db.sqlite';
createDir(dirname($dbfile), 0755);

$db = new Db('sqlite', $dbfile);
$db->saveQueries();

test('Db » __construct » Driver error', function ()
{
	new Db('json', '');
})
->throws(Exception::class);

test('Db » __construct » Database error', function ()
{
	new Db(Db::DRV_MYSQL, '');
})
->throws(Exception::class);

test('Db » connect', function() use (&$db, $dbfile)
{
	$db->connect();
	
	expect($dbfile)->toBeWritableFile();
});

test('Db » CREATE', function() use (&$db)
{
	$db->execute(
		'CREATE TABLE "post" (
			"id" INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL UNIQUE,
			"title" VARCHAR NOT NULL,
			"views" INTEGER NOT NULL DEFAULT 0,
			"created_at" INTEGER NOT NULL DEFAULT CURRENT_TIMESTAMP,
			"updated_at" INTEGER NOT NULL DEFAULT CURRENT_TIMESTAMP,
			"deleted_at" INTEGER DEFAULT NULL
		)'
	);

	$db->execute(
		'CREATE TABLE "comment" (
			"id" INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL UNIQUE,
			"post_id" INTEGER NOT NULL,
			"author" VARCHAR,
			"content" TEXT,
			"created_at" INTEGER NOT NULL DEFAULT CURRENT_TIMESTAMP,
			"updated_at" INTEGER NOT NULL DEFAULT CURRENT_TIMESTAMP
		)'
	);

	expect($db->isTable('post'))->toBeTrue();
	expect($db->isTable('comment'))->toBeTrue();
});

test('Db » INSERT » Explicit parameters', function() use (&$db)
{
	$id = $db->query()
		->insert('post')
		->set('title', ':title')
		->set('created_at', ':created_at')
		->set('updated_at', ':updated_at')
		->setParameter(':title', 'First post')
		->setParameters([
			[':created_at', (new DateTime())->format('Y-m-d H:i:s')],
			[':updated_at', (new DateTime())->format('Y-m-d H:i:s')]
		])
		->run();

	expect($id)->toBe(1);
});

test('Db » INSERT » Implicit parameters', function () use (&$db)
{
	$query = $db->query();
	$id = $query
		->insert('post')
		->values(
			['title', 'Second post', PDO::PARAM_STR],
			['created_at', 'NOW()'],
			['updated_at', 'NOW()'],
		)
		->run();

	expect($id)->toBe(2);
	expect($query->getLastInsertId())->toBe(2);
});

test('Db » UPDATE', function() use (&$db)
{
	$count = $db->query()
		->update('post')
		->values(
			['views', 1, PDO::PARAM_INT],
			['updated_at', 'NOW()']
		)
		->where('id = :id')
		->setParameter('id', 1, PDO::PARAM_INT)
		->run();

	expect($count)->toBe(1);

	$update = $db->query()
		->update('post')
		->values(
			'views = views + 10',
			['updated_at', 'NOW()']
		);
	$update->run();

	expect($update->getAffectedRows())->toBe(2);
});

test('Db » SELECT', function() use (&$db)
{
	$query = $db->query()->select('COUNT(*)')->from('post');

	expect($query->run()->fetchColumn())->toBe(2);

	$query = $db->query()
		->select('title, views')
		->from('post')
		->where('id = :id')
		->setParameter('id', 1, PDO::PARAM_INT)
		->run();

	expect($query->fetch())->toBeObject();

	$query = $db->query()->select('*')->from('post')->run();

	expect($query->fetchAll())->toBeArray()->toHaveCount(2);
});

test('Db » SELECT » Join', function() use (&$db)
{
	$comment = $db->query()->insert('comment');
		
	$comment
		->values(
			['post_id', 1, PDO::PARAM_INT],
			['author', "Author1"],
			['content', "First comment."],
			['created_at', 'NOW()'],
			['updated_at', 'NOW()'],
		)
		->run();

	$comment
		->values(
			['post_id', 1, PDO::PARAM_INT],
			['author', "Author2"],
			['content', "Second comment."],
			['created_at', 'NOW()'],
			['updated_at', 'NOW()'],
		)
		->run();

	$row = $db->query()
		->select('p.title, c.content')
		->from('post AS p')
		->innerJoin('comment AS c', 'c.post_id = p.id')
		->where('p.id = :id')
		->setParameter('id', 1, PDO::PARAM_INT)
		->run()
		->fetch();

	expect($row->title)->toBe('First post');
	expect($row->content)->toBe('First comment.');
});

test('Db » RAW', function () use (&$db)
{
	$col = $db->query()->raw('PRAGMA table_info("post")')->run()->fetch();

	expect($col->name)->toBe('id');
});

test('Db » DELETE', function() use (&$db)
{
	$deleted = $db->query()->delete('comment')->where('author = :author')
		->setParameter('author', 'Author2')
		->run();

	expect($deleted)->toBe(1);
});	

test('Db » TRUNCATE', function () use (&$db)
{
	$db->query()->truncate('comment')->run();
	$count = $db->query()
		->select('COUNT(*)')->from('comment')
		->run()->fetchColumn();
	expect($count)->toBe(0);
});

test('Db » Errors', function() use (&$db)
{
	expect(fn () => $db->query()->select('*')->from('pos-t')->run())
		->toThrow(UnexpectedValueException::class);

	expect(fn() => $db->query()->getAffectedRows())
		->toThrow(LogicException::class);

	expect(fn () => $db->query()->fetch())
		->toThrow(LogicException::class);

	expect(fn () => $db->query()->fetchColumn())
		->toThrow(LogicException::class);

	expect(fn () => $db->query()->fetchAll())
		->toThrow(LogicException::class);
});

class PostTable extends Table
{
	const TABLE_NAME = 'post';
	const COL_ID_NAME = 'id';
	const COL_CREATED_AT_NANE = 'created_at';
	const COL_UPDATED_AT_NAME = 'updated_at';
	const COL_DELETED_AT_NAME = 'deleted_at';

	protected function filterFields(array $aFields, int $id = 0): array
	{
		return $aFields;
	}
}

$dbpost = $db->table('post');

test('Table » add', function() use (&$dbpost)
{
	expect($dbpost)->toBeInstanceOf(PostTable::class);
	expect($dbpost->create())->toBeArray();

	expect($dbpost->add(['title' => 'Another post']))->toBeInt();
	expect(fn() => $dbpost->add(['field' => 'value', 'title' => 'Title']))
		->toThrow(PDOException::class);
});

test('Table » update', function () use (&$dbpost)
{
	expect($dbpost->update(3, ['title' => 'Another updated post']))->toBe(3);
});

test('Table » save', function () use (&$dbpost)
{
	expect($dbpost->save(['title' => 'May the forth be with you']))->toBe(4);
	expect($dbpost->save(['views' => 30], 4))->toBe(4);
});

test('Table » delete', function () use (&$dbpost)
{
	expect($dbpost->delete(3, true))->toBeTrue();
	expect($dbpost->delete(4))->toBeTrue();
});

test('Table » restore', function () use (&$dbpost)
{
	expect($dbpost->restore(3))->toBeFalse();
	expect($dbpost->restore(4))->toBeTrue();
});

test('Table » Find one', function() use (&$dbpost)
{
	expect($dbpost->findVal('title', 1))->toBe('First post');
	expect($dbpost->findVal('title', 5))->toBeFalse();
	expect($dbpost->findRow('title', 1)->title)->toBe('First post');
	expect($dbpost->findRow('title', 5))->toBeFalse();
	expect($dbpost->findAssoc('title', 1)['title'])->toBe('First post');
	expect($dbpost->findAssoc('title', 5))->toBeFalse();
	expect($dbpost->findId('title', 'May the forth be with you'))->toBe(4);
	expect($dbpost->findId('title', 'Not found title'))->toBe(0);
});

test('Table » Find rows', function() use (&$dbpost)
{
	$posts = $dbpost->findRows('id, title, views', 'views < 20', 'id');
	expect($posts->fetch())
		->toBeObject()
		->toHaveProperties(['id', 'title', 'views']);
});

test('Table » Find assocs', function () use (&$dbpost)
{
	$posts = $dbpost->findAssocs('id, title, views', 'views > 10', 'id');
	expect($posts->fetch())
		->toBeArray()
		->toHaveKeys(['id', 'title', 'views']);
});

test('Table » count', function() use (&$dbpost)
{
	expect($dbpost->count('views < 50'))->toBe(3);
});

test('Table » exists', function() use (&$dbpost)
{
	expect($dbpost->exists('views', 30))->toBeTrue();
	expect($dbpost->exists(['id', 'title'], [2, 'Second post']))->toBeTrue();
	expect($dbpost->exists(['views', 'title'], [10, 'Second post'], 2))->toBeFalse();
});

test('Table » getSelectableArray', function() use (&$dbpost)
{
	expect($dbpost->getSelectableArray('title', ['orderby' => 'id']))
		->toMatchArray([
			1 => 'First post',
			2 => 'Second post',
			4 => 'May the forth be with you'
		]);
});

test('Db » getSavedQueries', function() use (&$db)
{
	expect($db->getSavedQueries())->toHaveCount(43);
});

afterAll(function () use ($dbfile)
{
	$dbdir = dirname($dbfile);
	
	if (is_dir($dbdir))
		removeDir($dbdir);
});