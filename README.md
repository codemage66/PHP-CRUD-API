# MySQL-CRUD-API

Simple PHP script that adds a very basic API to a MySQL database

## Requirements

  - PHP 5.3 or higher with MySQLi enabled
  - Apache with mod_rewrite enabled (can also run on Nginx)

## Limitations

  - Public API only: no authentication or authorization
  - Read-only: no write or delete supported
  - No column selection: always returns full table
  - Single database

## Features

  - Very little code, easy to adapt and maintain
  - Streaming data, low memory footprint
  - Condensed JSON: first row contains field names
  - Optional white- and blacklist support for tables
  - JSONP support for cross-domain requests
  - Combined requests with wildcard support for table names
  - Pagination and search support

## Configuration

```
$config = array(
    "hostname"=>"localhost",
    "username"=>"root",
    "password"=>"root",
    "database"=>"blog",
    "read_whitelist"=>false,
    "read_blacklist"=>array("users"),
    "list_whitelist"=>false,
    "list_blacklist"=>array("users"),
);
```

## Example output

### List

```
GET http://localhost/api/categories
GET http://localhost/api/categories,users
GET http://localhost/api/cate*
GET http://localhost/api/cate*,user*
```

```
{"categories":{"columns":["id","name"],"records":[["1","Internet"],["3","Web development"]]}}
```

### Read

```
GET http://localhost/api/categories/1
```

```
{"id":"1","name":"Internet"}
```

## Installation

Put the files in a folder named "api" and edit config.php.dist and rename it to config.php. Let Apache serve the parent folder or configure the .htaccess RewriteBase to match the exposed part of the path.

## License

MIT
