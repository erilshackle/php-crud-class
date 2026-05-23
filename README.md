# QCrud

QCrud é uma biblioteca leve e poderosa para operações CRUD e construção de queries em PHP, inspirada em abordagens modernas como o Query Builder do Laravel, mas com foco em simplicidade, flexibilidade e controle total sobre o SQL.

## Instalação

Via Composer:

```bash
composer require eril/qcrud
```

## Configuração
Antes de usar, registre uma conexão PDO
```php
use Eril\QCrud\CRUD;
CRUD::registerConnection(function () {
    return new PDO(
        "mysql:host=localhost;dbname=test;charset=utf8",
        "root",
        "",
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]
    );
});
```

Também é possível passar diretamente uma instância de PDO:
```php
CRUD::registerConnection($pdo);
```

## Uso Básico (CRUD)

### Inserir registro
```php
$id = CRUD::table('users')->create([
    'name' => 'Erilando',
    'email' => 'email@example.com'
]);
```

### Buscar registros
```php
// Todos
$users = CRUD::table('users')->read();

// Por ID
$user = CRUD::table('users')->read(1);
```

### Atualizar registro
```php
CRUD::table('users')->update(1, [
    'name' => 'Novo nome'
]);
```

### Deletar registro
```php
CRUD::table('users')->delete(1);
```

### Consulta personalizada
```php
$users = CRUD::table('users')->select(
    'age > ? AND status = ?',
    [18, 'active']
);
```

## Query Builder
O QCrud inclui um construtor de queries fluente para consultas complexas.


### Exemplo básico
```php
$users = CRUD::query('users')
    ->where('age', '>', 18)
    ->orderBy('name')
    ->limit(10)
    ->get();
```
### Where avançado
```php
CRUD::query('users')
    ->where('status', 'active')
    ->orWhere('role', ['admin', 'editor'])
    ->get();
```
### Between
```php
CRUD::query('users')
    ->whereBetween('age', 18, 30)
    ->get();
```
### Joins
```php
CRUD::query('users u')
    ->join(['posts', 'p'], 'p.user_id = u.id')
    ->get();
```
### Group By e Having
```php
CRUD::query('orders')
    ->select('user_id, COUNT(*) as total')
    ->groupBy('user_id')
    ->having('total', '>', 5)
    ->get();
```
### Subqueries
```php
CRUD::query('users')
    ->whereSub('id', 'IN', function($q) {
        $q->select('user_id')
          ->where('status', 'active');
    }, 'orders')
    ->get();
```
### Paginação
```php
$result = CRUD::query('users')
    ->paginate(10, 1);

$data = $result['data'];
$meta = $result['pagination'];
```
## Métodos úteis

### Primeiro resultado
```php
$user = CRUD::query('users')->first();
```
### Contagem
```php
$total = CRUD::query('users')->count();
```
### Soma
```php
$total = CRUD::query('orders')->sum('price');
```
### Média
```php
$avg = CRUD::query('orders')->avg('price');
```
### Verificar existência
```php
$exists = CRUD::query('users')
    ->where('email', 'test@example.com')
    ->exists();
```
## Transações
```php
CRUD::beginTransaction();

CRUD::table('users')->create([...]);
CRUD::table('profiles')->create([...]);

CRUD::commit();
```
> Em caso de erro, a transação é revertida automaticamente.

## Debug

### Ver SQL gerado
```php
$sql = CRUD::query('users')->where('id', 1)->toSql();
```
### Bindings
```php
$params = CRUD::query('users')->where('id', 1)->getBindings();
```

## Requisitos
- PHP >= 8.0
- PDO habilitado

## Licença
> MIT License

## Autor
> Eril TS Carvalho
