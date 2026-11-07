<?php

namespace App\Support\Roster;

use App\Enums\Grade;
use App\Support\Text\SingleLineText;
use App\UseCases\Players\PlayerInput;
use Illuminate\Validation\ValidationException;
use Normalizer;

/**
 * 名簿の CSV を読み、1行ずつ検査する(AC-003-15〜18、ADR-0014)。
 * ファイル全体を読めないとき(文字コード・行数・見出し)は ValidationException、行ごとの問題は errors に入れる。
 */
final class PlayerCsv
{
    /** ファイルの大きさの上限。判定は PlayerCsvRequest で行う(AC-003-17) */
    public const int MAX_BYTES = 1024 * 1024;

    public const int MAX_ROWS = 500;

    public const string COLUMN_NAME = '表示名';

    public const string COLUMN_GRADE = '学年';

    public const string COLUMN_NUMBER = '背番号';

    public const string TEMPLATE = "\u{FEFF}表示名,学年,背番号\r\n山田 太郎,小3,10\r\n";

    /**
     * @param  list<CsvRow>  $rows
     * @param  list<CsvProblem>  $errors
     */
    private function __construct(
        public readonly array $rows,
        public readonly array $errors,
    ) {}

    public static function parse(string $bytes): self
    {
        // キーは見出しを含めた0始まりの位置。行番号はキー + 1(見出しが1行目、AC-003-15)
        $records = self::records(self::decode($bytes));
        $headerIndex = array_key_first($records);
        if ($headerIndex === null) {
            self::fail('CSVに見出しの行(表示名,学年,背番号)がありません。');
        }
        $header = $records[$headerIndex];
        unset($records[$headerIndex]);
        $columns = self::columns($header);
        if (count($records) > self::MAX_ROWS) {
            self::fail('CSVは'.self::MAX_ROWS.'行(見出しを除く)までです。行を減らしてください。');
        }
        if ($records === []) {
            self::fail('取り込む選手の行がありません。');
        }

        $rows = [];
        $errors = [];
        foreach ($records as $index => $record) {
            $line = $index + 1;
            $problems = [];
            $player = self::row($line, $record, $columns, $problems);
            if ($player !== null) {
                $rows[] = new CsvRow($line, $player);
            }
            array_push($errors, ...$problems);
        }

        return new self($rows, $errors);
    }

    /**
     * UTF-8 として正しく読めれば UTF-8(BOM は除く)、読めなければ CP932 として読む(AC-003-16、ADR-0014)。
     */
    private static function decode(string $bytes): string
    {
        if (str_starts_with($bytes, "\xEF\xBB\xBF")) {
            $bytes = substr($bytes, 3);
        }
        if (mb_check_encoding($bytes, 'UTF-8')) {
            return $bytes;
        }
        if (mb_check_encoding($bytes, 'CP932')) {
            return mb_convert_encoding($bytes, 'UTF-8', 'CP932');
        }

        self::fail('CSVの文字コードを読み取れません。UTF-8 か Shift_JIS で保存してください。');
    }

    /**
     * 空の行(Excel が末尾に足すものなど)は除く。行番号を保つため、キーは元の位置のまま詰めない。
     * 見出しと上限を1行超えた時点で読むのをやめる。短い行を大量に並べたファイルでメモリを使い切らないため。
     *
     * @return array<int, list<string>>
     */
    private static function records(string $text): array
    {
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new \RuntimeException('Could not open a temporary stream.');
        }
        fwrite($stream, $text);
        rewind($stream);

        $records = [];
        $index = -1;
        while (($record = fgetcsv($stream, null, ',', '"', '')) !== false) {
            $index++;
            $values = array_map(fn ($v) => (string) $v, $record);
            if (implode('', array_map(fn ($v) => SingleLineText::trim($v), $values)) !== '') {
                $records[$index] = $values;
                if (count($records) > self::MAX_ROWS + 1) {
                    break;
                }
            }
        }
        fclose($stream);

        return $records;
    }

    /**
     * @param  list<string>  $header
     * @return array{name: int, grade: int, number: int|null}
     */
    private static function columns(array $header): array
    {
        $names = array_map(fn ($v) => (string) Normalizer::normalize(SingleLineText::trim($v), Normalizer::FORM_KC), $header);
        $find = fn (string $column) => ($i = array_search($column, $names, true)) === false ? null : (int) $i;

        $name = $find(self::COLUMN_NAME);
        $grade = $find(self::COLUMN_GRADE);
        foreach ([self::COLUMN_NAME => $name, self::COLUMN_GRADE => $grade] as $column => $position) {
            if ($position === null) {
                self::fail("1行目 見出し:「{$column}」の列がありません。見出しは「表示名,学年,背番号」にしてください。");
            }
        }

        /** @var int $name */
        /** @var int $grade */
        return ['name' => $name, 'grade' => $grade, 'number' => $find(self::COLUMN_NUMBER)];
    }

    /**
     * @param  list<string>  $record
     * @param  array{name: int, grade: int, number: int|null}  $columns
     * @param  list<CsvProblem>  $problems
     */
    private static function row(int $line, array $record, array $columns, array &$problems): ?PlayerInput
    {
        // 値は数式として扱わず、文字列のまま保存する。= + - @ で始まっても変えない(AC-003-18)
        $name = SingleLineText::trim($record[$columns['name']] ?? '');
        if ($name === '') {
            $problems[] = new CsvProblem($line, self::COLUMN_NAME, "{$line}行目 表示名:入力してください");
        } elseif (mb_strlen($name) > 30) {
            $problems[] = new CsvProblem($line, self::COLUMN_NAME, "{$line}行目 表示名:30文字以内にしてください");
        } elseif (preg_match(SingleLineText::FORBIDDEN_PATTERN, $name) === 1) {
            $problems[] = new CsvProblem($line, self::COLUMN_NAME, "{$line}行目 表示名:改行などの記号は使えません");
        }

        $gradeText = SingleLineText::trim($record[$columns['grade']] ?? '');
        $grade = Grade::fromLabel($gradeText);
        if ($gradeText === '') {
            $problems[] = new CsvProblem($line, self::COLUMN_GRADE, "{$line}行目 学年:入力してください(未就学、小1〜小6、中1〜中3)");
        } elseif ($grade === null) {
            $shown = mb_strimwidth(preg_replace(SingleLineText::FORBIDDEN_PATTERN, '', $gradeText) ?? '', 0, 12, '…');
            $problems[] = new CsvProblem($line, self::COLUMN_GRADE, "{$line}行目 学年:『{$shown}』は選べません(未就学、小1〜小6、中1〜中3)");
        }

        $number = null;
        $numberText = $columns['number'] === null ? '' : (string) Normalizer::normalize(SingleLineText::trim($record[$columns['number']] ?? ''), Normalizer::FORM_KC);
        if ($numberText !== '') {
            if (preg_match('/\A[0-9]{1,3}\z/', $numberText) === 1) {
                $number = (int) $numberText;
            } else {
                $problems[] = new CsvProblem($line, self::COLUMN_NUMBER, "{$line}行目 背番号:0〜999の数字にしてください");
            }
        }

        $hasError = array_filter($problems, fn (CsvProblem $p) => $p->line === $line) !== [];

        return $hasError || $grade === null ? null : new PlayerInput($name, $grade, $number);
    }

    private static function fail(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }
}
