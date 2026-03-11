# wlib/db

Ensemble de classes PHP pour interagir avec vos bases de données MySQL, SQLite ou PostgreSQL.

## Installation

```shell
composer require wlib/db
```

## Documentation

La bibliothèque **wlib/db** offre une interface complète pour interagir avec les bases de données **MySQL**, **SQLite** et **PostgreSQL** (support incomplet pour PostgreSQL). Elle fournit des classes puissantes pour construire des requêtes **SQL** de manière sécurisée et expressive.

> [!NOTE]
> Le code est documenté, n'hésitez pas à le consulter en complément de cette documentation.

### Connexion à la base de données

```php
// Connexion SQLite
$db = new Db(Db::DRV_SQLTE, '/chemin/vers/base.sqlite');

// Connexion MySQL
$db = new Db(Db::DRV_MYSQL, 'nom_base', 'utilisateur', 'motdepasse', 'localhost', 3306);

// Connexion PostgreSQL
$db = new Db(Db::DRV_PGSQL, 'nom_base', 'utilisateur', 'motdepasse', 'localhost', 5432);

$db->connect();
```

> [!NOTE]
> La connexion est automatiquement établie lors de la première requête si vous n'appelez pas explicitement `connect()`.

### Construction de requêtes avec Query

#### Requêtes SELECT

```php
// Sélection simple
$result = $db->query()
    ->select('id, title, views')
    ->from('post')
    ->where('id = :id')
    ->setParameter('id', 1, PDO::PARAM_INT)
    ->run();

// Récupération des résultats
$row = $result->fetch(); // Un seul résultat
$rows = $result->fetchAll(); // Tous les résultats
$count = $result->fetchColumn(); // Première colonne
```

#### Jointures

```php
// Jointure interne
$result = $db->query()
    ->select('p.title, c.content')
    ->from('post AS p')
    ->innerJoin('comment AS c', 'c.post_id = p.id')
    ->where('p.id = :id')
    ->setParameter('id', 1, PDO::PARAM_INT)
    ->run();
```

#### Requêtes INSERT

```php
// Insertion avec paramètres explicites
$id = $db->query()
    ->insert('post')
    ->set('title', ':title')
    ->set('created_at', ':created_at')
    ->setParameter(':title', 'Mon premier article')
    ->setParameter(':created_at', (new DateTime())->format('Y-m-d H:i:s'))
    ->run();

// Insertion avec valeurs directes
$id = $db->query()
    ->insert('post')
    ->values(
        ['title', 'Second article', PDO::PARAM_STR],
        ['created_at', 'NOW()'],
        ['updated_at', 'NOW()']
    )
    ->run();
```

#### Requêtes UPDATE

```php
// Mise à jour simple
$count = $db->query()
    ->update('post')
    ->values(
        ['views', 1, PDO::PARAM_INT],
        ['updated_at', 'NOW()']
    )
    ->where('id = :id')
    ->setParameter('id', 1, PDO::PARAM_INT)
    ->run();

// Mise à jour avec expression SQL
$db->query()
    ->update('post')
    ->values(
        ['views', Db::literal('views + 1')],
        ['updated_at', 'NOW()']
    )
    ->run();
```

#### Requêtes DELETE et TRUNCATE

```php
// Suppression conditionnelle
$deleted = $db->query()
    ->delete('comment')
    ->where('author = :author')
    ->setParameter('author', 'Auteur1')
    ->run();

// Vider une table
$db->query()->truncate('comment')->run();
```

### Gestion des tables avec Table

La classe `Table` fournit une interface orientée objet pour manipuler les données :

```php
// Création d'une classe de table
class PostTable extends Table
{
    const TABLE_NAME = 'post';
    const COL_ID_NAME = 'id';
    // ... autres constantes
}

// Utilisation
$postTable = $db->table('post');

// Création d'un enregistrement
$newId = $postTable->add(['title' => 'Nouvel article']);

// Mise à jour
$postTable->update(1, ['title' => 'Titre mis à jour']);

// Suppression
$postTable->delete(1);

// Recherche
$post = $postTable->findRow('*', 1); // Retourne un objet
$posts = $postTable->findRows('id, title', 'views > 10', 'id DESC');

// Comptage
$count = $postTable->count('views < 50');

// Vérification d'existence
$exists = $postTable->exists('title', 'Mon article');
```

### Fonctionnalités avancées

#### Littéraux SQL

```php
// Utilisation de littéraux pour les expressions SQL
$db->query()
    ->update('post')
    ->values(
        ['views', Db::literal('views + 1')],
        ['updated_at', 'NOW()']
    )
    ->run();
```

#### Transactions

```php
try
{
    $db->beginTransaction();

    // Exécuter plusieurs requêtes
    $db->query()->insert('post')->values(...)->run();
    $db->query()->update('user')->values(...)->run();

    $db->commit();
}
catch (Exception $e)
{
    $db->rollBack();
    // Gestion de l'erreur
}
```

#### Événements

```php
// Événements avant/après exécution
$db->onBeforeExecute(function($sql, $params) {
    // Log ou modification avant exécution
});

$db->onAfterExecute(function($sql, $params, $time) {
    // Log ou traitement après exécution
});
```

#### Sécurité

```php
// Protection contre les injections SQL
$unsafeInput = $_GET['user_input'];
$safeQuery = $db->query()
    ->select('*')
    ->from('users')
    ->where('username = :username')
    ->setParameter('username', $unsafeInput)
    ->run();
```

#### Gestion des erreurs

```php
try
{
    $result = $db->query()
        ->select('*')
        ->from('inexistante')
        ->run();
}
catch (UnexpectedValueException $e)
{
    // Gestion de l'erreur de table inexistante
}
catch (PDOException $e)
{
    // Gestion des erreurs PDO
}
```

### Exemples complets

#### Création de tables

```php
$db->execute("
    CREATE TABLE post (
        id INTEGER PRIMARY KEY AUTO_INCREMENT,
        title VARCHAR(255) NOT NULL,
        views INTEGER NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    )
");
```

#### Requêtes complexes

```php
// Requête avec jointure, regroupement et tri
$result = $db->query()
    ->select('u.name, COUNT(p.id) as post_count')
    ->from('users AS u')
    ->leftJoin('post AS p', 'p.user_id = u.id')
    ->groupBy('u.id')
    ->orderBy('post_count DESC')
    ->limit(10)
    ->run();
```

Pour plus d'exemples, consultez le fichier de tests unitaires `/tests/Unit/DbTest.php` qui contient de nombreux cas d'utilisation concrets.

## Tests unitaires

Le fichier `/tests/Unit/DbTest.php` contient de nombreux exemples de mise en oeuvre.

Les tests unitaires font usage de la libraire [Pest](https://pestphp.com/).
