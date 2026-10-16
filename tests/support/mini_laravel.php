<?php

declare(strict_types=1);

/*
 * Team management - by floiofficial.
 *
 * A tiny stand-in for the parts of Laravel's query builder that TicketService uses, running on SQLite.
 * Purpose: test the ticket LOGIC without installing the panel/composer. It is NOT Laravel - the real
 * query builder can differ in details, so this does not replace a test on a real panel.
 */

namespace Illuminate\Database {
    interface ConnectionInterface
    {
    }
}

namespace MiniLaravel {
    use Illuminate\Database\ConnectionInterface;

    final class Coll implements \IteratorAggregate, \Countable
    {
        public function __construct(private array $items = [])
        {
        }

        public function all(): array
        {
            return $this->items;
        }

        public function count(): int
        {
            return count($this->items);
        }

        public function getIterator(): \Traversable
        {
            return new \ArrayIterator($this->items);
        }
    }

    final class Query
    {
        public string $cols = '*';
        private array $wheres = [];
        private array $binds = [];
        private array $orders = [];
        private array $groups = [];
        private ?int $limit = null;
        private ?int $offset = null;

        public function __construct(private \PDO $pdo, public ?string $table = null)
        {
        }

        public function select(mixed $c): self { $this->cols = is_array($c) ? implode(',', $c) : $c; return $this; }
        public function from(string $t): self { $this->table = $t; return $this; }
        public function selectRaw(string $sql): self { $this->cols = $sql; return $this; }
        public function groupBy(string $c): self { $this->groups[] = $c; return $this; }

        private function add(string $bool, string $sql, array $bind = []): self
        {
            $this->wheres[] = [$bool, $sql];
            array_push($this->binds, ...$bind);

            return $this;
        }

        private function sub(\Closure $cl): self
        {
            $q = new self($this->pdo);
            $cl($q);

            return $q;
        }

        public function where(mixed $c, mixed $op = null, mixed $v = null): self
        {
            if ($c instanceof \Closure) {
                [$s, $b] = $this->sub($c)->compileWhere();

                return $this->add('AND', "($s)", $b);
            }
            if (func_num_args() === 2) {
                $v = $op;
                $op = '=';
            }

            return $this->add('AND', "$c $op ?", [$v]);
        }

        private function inSql(string $col, mixed $v, bool $not): array
        {
            if ($v instanceof \Closure) {
                [$s, $b] = $this->sub($v)->compile();

                return ["$col " . ($not ? 'NOT ' : '') . "IN ($s)", $b];
            }

            return ["$col " . ($not ? 'NOT ' : '') . 'IN (' . implode(',', array_fill(0, count($v), '?')) . ')', array_values($v)];
        }

        public function whereIn(string $c, mixed $v): self { [$s, $b] = $this->inSql($c, $v, false); return $this->add('AND', $s, $b); }
        public function whereNotIn(string $c, mixed $v): self { [$s, $b] = $this->inSql($c, $v, true); return $this->add('AND', $s, $b); }
        public function whereRaw(string $s, array $b = []): self { return $this->add('AND', $s, $b); }
        public function orderBy(string $c, string $dir = 'asc'): self { $this->orders[] = "$c " . (strtolower($dir) === 'desc' ? 'DESC' : 'ASC'); return $this; }
        public function orderByDesc(string $c): self { return $this->orderBy($c, 'desc'); }
        public function orderByRaw(string $sql): self { $this->orders[] = $sql; return $this; }
        public function limit(int $n): self { $this->limit = $n; return $this; }
        public function offset(int $n): self { $this->offset = $n; return $this; }

        public function compileWhere(): array
        {
            $s = '';
            foreach ($this->wheres as $i => [$bool, $sql]) {
                $s .= ($i ? " $bool " : '') . $sql;
            }

            return [$s, $this->binds];
        }

        public function compile(bool $ordered = false): array
        {
            [$w, $b] = $this->compileWhere();
            $sql = "SELECT {$this->cols} FROM {$this->table}" . ($w !== '' ? " WHERE $w" : '');
            if ($this->groups) {
                $sql .= ' GROUP BY ' . implode(', ', $this->groups);
            }
            if ($this->orders) {
                $sql .= ' ORDER BY ' . implode(', ', $this->orders);
            }
            if ($this->limit !== null) {
                $sql .= " LIMIT {$this->limit}" . ($this->offset !== null ? " OFFSET {$this->offset}" : '');
            }

            return [$sql, $b];
        }

        private function run(string $sql, array $b): \PDOStatement
        {
            $st = $this->pdo->prepare($sql);
            $st->execute($b);

            return $st;
        }

        public function get(?array $cols = null): Coll
        {
            if ($cols) {
                $this->cols = implode(',', $cols);
            }
            [$s, $b] = $this->compile();

            return new Coll($this->run($s, $b)->fetchAll(\PDO::FETCH_OBJ));
        }

        public function pluck(string $col): Coll
        {
            $rows = $this->get([$col])->all();

            return new Coll(array_map(static fn ($r) => $r->$col, $rows));
        }

        public function first(?array $cols = null): ?object
        {
            $this->limit = 1;
            $rows = $this->get($cols)->all();

            return $rows[0] ?? null;
        }

        public function value(string $col): mixed
        {
            $row = $this->first([$col]);

            return $row === null ? null : $row->$col;
        }

        public function count(): int
        {
            $this->cols = 'COUNT(*)';
            $this->orders = [];
            [$s, $b] = $this->compile();

            return (int) $this->run($s, $b)->fetchColumn();
        }

        public function exists(): bool { return $this->count() > 0; }

        public function update(array $a): int
        {
            [$w, $b] = $this->compileWhere();
            $set = implode(',', array_map(fn ($k) => "$k=?", array_keys($a)));

            return $this->run("UPDATE {$this->table} SET $set" . ($w !== '' ? " WHERE $w" : ''), [...array_values($a), ...$b])->rowCount();
        }

        public function increment(string $col, int $by = 1, array $extra = []): int
        {
            [$w, $b] = $this->compileWhere();
            $set = ["$col = $col + $by", ...array_map(fn ($k) => "$k=?", array_keys($extra))];

            return $this->run("UPDATE {$this->table} SET " . implode(',', $set) . ($w !== '' ? " WHERE $w" : ''), [...array_values($extra), ...$b])->rowCount();
        }

        public function insert(array $a): bool
        {
            $this->run("INSERT INTO {$this->table} (" . implode(',', array_keys($a)) . ') VALUES (' . implode(',', array_fill(0, count($a), '?')) . ')', array_values($a));

            return true;
        }

        public function insertGetId(array $a): int
        {
            $this->insert($a);

            return (int) $this->pdo->lastInsertId();
        }

        public function delete(): int
        {
            [$w, $b] = $this->compileWhere();

            return $this->run("DELETE FROM {$this->table}" . ($w !== '' ? " WHERE $w" : ''), $b)->rowCount();
        }
    }

    final class Connection implements ConnectionInterface
    {
        public function __construct(public readonly \PDO $pdo)
        {
        }

        public function table(string $t): Query
        {
            return new Query($this->pdo, $t);
        }

        public function transaction(\Closure $f): mixed
        {
            $this->pdo->beginTransaction();
            try {
                $r = $f();
                $this->pdo->commit();

                return $r;
            } catch (\Throwable $e) {
                $this->pdo->rollBack();
                throw $e;
            }
        }
    }
}
