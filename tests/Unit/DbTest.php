<?php declare(strict_types=1);

use wlib\Db\Db;
use wlib\Db\Table;

date_default_timezone_set('Europe/Paris');

$dbfile = __DIR__ .'/tmp/db.sqlite';
createDir(dirname($dbfile), 0755);

dataset('databases', [
	Db::DRV_SQLTE => new Db(Db::DRV_SQLTE, $dbfile /* or ':memory:' */),
	Db::DRV_MYSQL => new Db(Db::DRV_MYSQL, 'test', 'root', '', '127.0.0.1', 3306)
]);

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


test('Db » __construct » Driver error', function() { new Db('json', ''); })
->throws(Exception::class);


test('Db » __construct » Database error', function() { new Db(Db::DRV_MYSQL, ''); })
->throws(Exception::class);


test('Db » connect', function(&$db)
{
	$db->connect();
	$db->saveQueries();
})
->with('databases')
->throwsNoExceptions();


test('Db » CREATE', function(&$db)
{
	$sAutoIncrement = ($db->getDriver() == Db::DRV_SQLTE ? 'AUTOINCREMENT' : 'AUTO_INCREMENT');
	
	$db->execute(
		"CREATE TABLE post (
			id INTEGER PRIMARY KEY $sAutoIncrement,
			title VARCHAR(255) NOT NULL,
			views INTEGER NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			deleted_at DATETIME DEFAULT NULL
		)"
	);

	$db->execute(
		"CREATE TABLE comment (
			id INTEGER PRIMARY KEY $sAutoIncrement,
			post_id INTEGER NOT NULL,
			author VARCHAR(80),
			content TEXT,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
		)"
	);

	expect($db->isTable('post'))->toBeTrue();
	expect($db->isTable('comment'))->toBeTrue();
})
->with('databases');


test('Db » INSERT » Explicit parameters', function(&$db)
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
})
->with('databases');


test('Db » INSERT » Implicit parameters', function(&$db)
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
})
->with('databases');


test('Db » UPDATE', function(&$db)
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
})
->with('databases');


test('Db » SELECT', function(&$db)
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
})
->with('databases');


test('Db » SELECT » Join', function(&$db)
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
})
->with('databases');


test('Db » RAW', function(&$db)
{
	$sSQL = ($db->getDriver() == Db::DRV_SQLTE
		? 'PRAGMA table_info("post")'
		: 'SHOW COLUMNS FROM post;'
	);

	$col = $db->query()->raw($sSQL)->run()->fetch();

	if ($db->getDriver() == Db::DRV_SQLTE)
		expect($col->name)->toBe('id');
	else
		expect($col->Field)->toBe('id');
})
->with('databases');


test('Db » Literals', function(&$db)
{
	$db->query()
		->update('post')
		->values(
			['views', Db::literal('views + 2')],
			['updated_at', 'NOW()']
		)
		->run();

	$iViews = $db->query()
		->select('views')
		->from('post')
		->where('id = :id')
		->setParameter('id', 1, PDO::PARAM_INT)
		->run()->fetchColumn(0);

	expect($iViews)->toBe(13);
})
->with('databases');


test('Db » DELETE', function(&$db)
{
	$deleted = $db->query()->delete('comment')->where('author = :author')
		->setParameter('author', 'Author2')
		->run();

	expect($deleted)->toBe(1);
})
->with('databases');	


test('Db » TRUNCATE', function(&$db)
{
	$db->query()->truncate('comment')->run();
	$count = $db->query()
		->select('COUNT(*)')->from('comment')
		->run()->fetchColumn();

	expect($count)->toBe(0);
})
->with('databases');


test('Db » Errors', function(&$db)
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
})
->with('databases');


test('Table » add', function(&$db)
{
	$dbpost = $db->table('post');

	expect($dbpost)->toBeInstanceOf(PostTable::class);
	expect($dbpost->create())->toBeArray();

	expect($dbpost->add(['title' => 'Another post']))->toBeInt();
	expect(fn() => $dbpost->add(['field' => 'value', 'title' => 'Title']))
		->toThrow(PDOException::class);
})
->with('databases');


test('Table » update', function(&$db)
{
	$dbpost = $db->table('post');

	expect($dbpost->update(3, ['title' => 'Another updated post']))->toBe(3);
})
->with('databases');


test('Table » save', function(&$db)
{
	$dbpost = $db->table('post');

	expect($dbpost->save(['title' => 'May the forth be with you']))->toBe(4);
	expect($dbpost->save(['views' => 30], 4))->toBe(4);
})
->with('databases');


test('Table » delete', function(&$db)
{
	$dbpost = $db->table('post');

	expect($dbpost->delete(3, true))->toBeTrue();
	expect($dbpost->delete(4))->toBeTrue();
})
->with('databases');


test('Table » restore', function(&$db)
{
	$dbpost = $db->table('post');

	expect($dbpost->restore(3))->toBeFalse();
	expect($dbpost->restore(4))->toBeTrue();
})
->with('databases');


test('Table » Find one', function(&$db)
{
	$dbpost = $db->table('post');

	expect($dbpost->findVal('title', 1))->toBe('First post');
	expect($dbpost->findVal('title', 5))->toBeFalse();
	expect($dbpost->findRow('title', 1)->title)->toBe('First post');
	expect($dbpost->findRow('title', 5))->toBeFalse();
	expect($dbpost->findAssoc('title', 1)['title'])->toBe('First post');
	expect($dbpost->findAssoc('title', 5))->toBeFalse();
	expect($dbpost->findId('title', 'May the forth be with you'))->toBe(4);
	expect($dbpost->findId('title', 'Not found title'))->toBe(0);
})
->with('databases');


test('Table » Find rows', function(&$db)
{
	$dbpost = $db->table('post');

	$posts = $dbpost->findRows('id, title, views', 'views < 20', 'id');
	expect($posts->fetch())
		->toBeObject()
		->toHaveProperties(['id', 'title', 'views']);
})
->with('databases');


test('Table » Find assocs', function(&$db)
{
	$dbpost = $db->table('post');

	$posts = $dbpost->findAssocs('id, title, views', 'views > 10', 'id');
	expect($posts->fetch())
		->toBeArray()
		->toHaveKeys(['id', 'title', 'views']);
})
->with('databases');


test('Table » count', function(&$db)
{
	$dbpost = $db->table('post');

	expect($dbpost->count('views < 50'))->toBe(3);
})
->with('databases');


test('Table » exists', function(&$db)
{
	$dbpost = $db->table('post');

	expect($dbpost->exists('views', 30))->toBeTrue();
	expect($dbpost->exists(['id', 'title'], [2, 'Second post']))->toBeTrue();
	expect($dbpost->exists(['views', 'title'], [10, 'Second post'], 2))->toBeFalse();
})
->with('databases');


test('Table » getSelectableArray', function(&$db)
{
	$dbpost = $db->table('post');

	expect($dbpost->getSelectableArray('title', ['orderby' => 'id']))
		->toMatchArray([
			1 => 'First post',
			2 => 'Second post',
			4 => 'May the forth be with you'
		]);
})
->with('databases');


test('Db » DROP', function(&$db)
{
	$db->execute('DROP TABLE post');
	$db->execute('DROP TABLE comment');

	$db->clearCaches();

	expect($db->isTable('post'))->toBeFalse();
	expect($db->isTable('comment'))->toBeFalse();
})
->with('databases');


test('Db » getSavedQueries', function(&$db)
{
	expect($db->getSavedQueries())->toBeGreaterThan(40);
})
->with('databases');


afterAll(function() use ($dbfile)
{
	$dbdir = dirname($dbfile);
	
	if (is_dir($dbdir))
		removeDir($dbdir);
});