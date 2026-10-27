<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

session_set_cookie_params([
    'httponly' => true,
    'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'samesite' => 'Lax',
]);
session_start();

// Companion details and tutorial hints used by the page templates.
$companions = [
    'ember' => ['name' => 'Ember the Fox', 'element' => 'fire', 'emoji' => '🦊', 'stages' => ['Baby Cub', 'Swift Scout', 'Flame Champion', 'Inferno Master', 'Ascended Phoenix-Fox']],
    'aqua' => ['name' => 'Aqua the Axolotl', 'element' => 'water', 'emoji' => '🦎', 'stages' => ['Tiny Tadpole', 'River Drifter', 'Tide Keeper', 'Abyss Guardian', 'Ocean Spirit']],
    'terra' => ['name' => 'Terra the Turtle', 'element' => 'earth', 'emoji' => '🐢', 'stages' => ['Little Sprout', 'Moss Wanderer', 'Stone Shell', 'Forest Ancient', 'Worldroot']],
    'zephyr' => ['name' => 'Zephyr the Falcon', 'element' => 'air', 'emoji' => '🦅', 'stages' => ['Small Fledgling', 'Breeze Runner', 'Sky Dancer', 'Storm Rider', 'Celestial Roc']],
];
$stageLevels = [1, 5, 10, 15, 20];
$tutorialGuidance = [
    'tutorial-basics' => [
        'A deck keeps related question-and-answer cards together, making it easier to focus on one topic.',
        'Create a deck with the New Deck form below, then return to this lesson.',
        'Try answering from memory before revealing the example, then rate how difficult recall felt.',
    ],
    'tutorial-streaks' => [
        'Recall what you need to do to keep a daily study streak going.',
        'Compare calendar days, not the number of sessions you complete.',
        'Think about what happens to a streak after a day without studying.',
    ],
    'tutorial-pets' => [
        'Recall which study activity earns treats for your companion.',
        'Consider the levels your companion needs to reach each evolution.',
        'Think about how locked forms become available as your companion levels up.',
    ],
];

// Escape values before inserting them into HTML.
function h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// Reuse a session token to protect state-changing forms from cross-site requests.
function csrf_token(): string
{
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '">';
}

// Store one-time feedback and use POST/Redirect/GET after form submissions.
function redirect_to(string $view, string $message = '', string $type = 'success'): never
{
    if ($message !== '') {
        $_SESSION['flash'] = ['message' => $message, 'type' => $type];
    }
    header('Location: index.php?view=' . rawurlencode($view));
    exit;
}

function post_string(string $key): string
{
    $value = $_POST[$key] ?? '';
    return is_string($value) ? trim($value) : '';
}

// Load a deck and its ordered cards together for editing and study sessions.
function load_deck(PDO $pdo, int $deckId): ?array
{
    $statement = $pdo->prepare('SELECT id, slug, title, description, category FROM decks WHERE id = ?');
    $statement->execute([$deckId]);
    $deck = $statement->fetch();
    if (!$deck) {
        return null;
    }
    $cards = $pdo->prepare('SELECT id, question, answer, position FROM cards WHERE deck_id = ? ORDER BY position, id');
    $cards->execute([$deckId]);
    $deck['cards'] = $cards->fetchAll();
    return $deck;
}

function validated_cards(mixed $questions, mixed $answers): array
{
    if (!is_array($questions) || !is_array($answers) || count($questions) !== count($answers) || count($questions) > 50) {
        throw new DomainException('Add between 1 and 50 complete question-and-answer cards.');
    }
    $cards = [];
    foreach ($questions as $index => $questionValue) {
        $question = is_string($questionValue) ? trim($questionValue) : '';
        $answerValue = $answers[$index] ?? '';
        $answer = is_string($answerValue) ? trim($answerValue) : '';
        if ($question === '' && $answer === '') {
            continue;
        }
        // Reject half-filled cards rather than silently saving incomplete prompts.
        if ($question === '' || $answer === '') {
            throw new DomainException('Each card needs both a question and an answer.');
        }
        if (mb_strlen($question) > 500 || mb_strlen($answer) > 1200) {
            throw new DomainException('Questions must be at most 500 characters and answers at most 1,200 characters.');
        }
        $cards[] = ['question' => $question, 'answer' => $answer];
    }
    if ($cards === []) {
        throw new DomainException('Add at least one complete question-and-answer card.');
    }
    return $cards;
}

function save_cards(PDO $pdo, int $deckId, array $cards): void
{
    // Replace the deck's cards; callers wrap this with the deck update transaction.
    $pdo->prepare('DELETE FROM cards WHERE deck_id = ?')->execute([$deckId]);
    $insert = $pdo->prepare('INSERT INTO cards (deck_id, question, answer, position) VALUES (?, ?, ?, ?)');
    foreach ($cards as $index => $card) {
        $insert->execute([$deckId, $card['question'], $card['answer'], $index + 1]);
    }
}

// Restore the current card from session state, clamping the saved position.
function current_study(PDO $pdo): ?array
{
    $deckId = filter_var($_SESSION['study_deck_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$deckId) {
        return null;
    }
    $deck = load_deck($pdo, $deckId);
    if (!$deck || $deck['cards'] === []) {
        unset($_SESSION['study_deck_id'], $_SESSION['study_index'], $_SESSION['study_checked']);
        return null;
    }
    $index = max(0, min((int)($_SESSION['study_index'] ?? 0), count($deck['cards']) - 1));
    return ['deck' => $deck, 'index' => $index, 'card' => $deck['cards'][$index]];
}

// Convert earned XP into levels while keeping the remainder for the next level.
function award_xp(PDO $pdo, string $companionId, int $points): void
{
    $statement = $pdo->prepare('SELECT level, xp FROM companion_progress WHERE companion_id = ? FOR UPDATE');
    $statement->execute([$companionId]);
    $progress = $statement->fetch();
    if (!$progress) {
        throw new RuntimeException('Companion progress is not initialized.');
    }
    $totalXp = (int)$progress['xp'] + $points;
    $level = (int)$progress['level'] + intdiv($totalXp, 100);
    $xp = $totalXp % 100;
    $update = $pdo->prepare('UPDATE companion_progress SET level = ?, xp = ? WHERE companion_id = ?');
    $update->execute([$level, $xp, $companionId]);
}

// Stream all decks and progress as a downloadable JSON backup.
function export_backup(PDO $pdo): never
{
    $decks = $pdo->query('SELECT id, slug, title, description, category FROM decks ORDER BY id')->fetchAll();
    foreach ($decks as &$deck) {
        $statement = $pdo->prepare('SELECT question, answer FROM cards WHERE deck_id = ? ORDER BY position, id');
        $statement->execute([$deck['id']]);
        $deck['cards'] = $statement->fetchAll();
        unset($deck['id']);
    }
    unset($deck);
    $state = $pdo->query('SELECT treats, streak, last_study_date, selected_pet FROM app_state WHERE id = 1')->fetch();
    $progress = $pdo->query('SELECT companion_id, level, xp FROM companion_progress')->fetchAll();
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="vault-backup.json"');
    echo json_encode(['version' => 1, 'decks' => $decks, 'state' => $state, 'companions' => $progress], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

// Connect once per request; the page can still render an actionable error if unavailable.
$pdo = null;
$databaseError = '';
try {
    $pdo = database_connection();
} catch (Throwable $error) {
    error_log('Vault database connection failed: ' . $error->getMessage());
    $databaseError = 'Vault could not connect to MySQL. Check the database settings in config.php / your hosting environment, then import database.sql.';
}

if ($pdo instanceof PDO && ($_GET['action'] ?? '') === 'export') {
    try {
        export_backup($pdo);
    } catch (Throwable $error) {
        error_log('Vault backup export failed: ' . $error->getMessage());
        redirect_to('decks', 'The backup could not be exported. Check the server error log.', 'error');
    }
}

// Process all form actions before rendering, then redirect to prevent duplicate submissions.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $view = in_array($_POST['view'] ?? '', ['home', 'decks', 'study', 'pets'], true) ? $_POST['view'] : 'home';
    try {
        if (!($pdo instanceof PDO)) {
            throw new RuntimeException('The database is not available. Check the database configuration.');
        }
        if (!hash_equals(csrf_token(), post_string('csrf_token'))) {
            throw new DomainException('This form expired. Reload the page and try again.');
        }

        $action = post_string('action');
        switch ($action) {
            case 'create_deck':
            case 'update_deck':
                // Validate and save the deck and its cards as one atomic change.
                $title = post_string('title');
                $description = post_string('description');
                if ($title === '' || mb_strlen($title) > 80) {
                    throw new DomainException('Enter a deck title of 1 to 80 characters.');
                }
                if (mb_strlen($description) > 2000) {
                    throw new DomainException('Deck descriptions must be at most 2,000 characters.');
                }
                $cards = validated_cards($_POST['questions'] ?? null, $_POST['answers'] ?? null);
                $pdo->beginTransaction();
                if ($action === 'create_deck') {
                    $insert = $pdo->prepare('INSERT INTO decks (title, description, category) VALUES (?, ?, ?)');
                    $insert->execute([$title, $description, 'Custom']);
                    $deckId = (int)$pdo->lastInsertId();
                } else {
                    $deckId = filter_var($_POST['deck_id'] ?? null, FILTER_VALIDATE_INT);
                    if (!$deckId || !load_deck($pdo, (int)$deckId)) {
                        throw new DomainException('That deck no longer exists.');
                    }
                    $update = $pdo->prepare('UPDATE decks SET title = ?, description = ? WHERE id = ?');
                    $update->execute([$title, $description, $deckId]);
                }
                save_cards($pdo, $deckId, $cards);
                $pdo->commit();
                redirect_to('decks', $action === 'create_deck' ? 'Deck created and saved.' : 'Deck updated.');

            case 'delete_deck':
                $deckId = filter_var($_POST['deck_id'] ?? null, FILTER_VALIDATE_INT);
                if (!$deckId) {
                    throw new DomainException('Choose a valid deck to delete.');
                }
                $delete = $pdo->prepare('DELETE FROM decks WHERE id = ?');
                $delete->execute([$deckId]);
                if (isset($_SESSION['study_deck_id']) && (int)$_SESSION['study_deck_id'] === (int)$deckId) {
                    unset($_SESSION['study_deck_id'], $_SESSION['study_index'], $_SESSION['study_checked']);
                }
                redirect_to('decks', $delete->rowCount() ? 'Deck deleted.' : 'That deck was already removed.');

            case 'start_study':
            case 'start_tutorial':
                $deckId = filter_var($_POST['deck_id'] ?? null, FILTER_VALIDATE_INT);
                $deck = $deckId ? load_deck($pdo, (int)$deckId) : null;
                if (!$deck || $deck['cards'] === []) {
                    throw new DomainException('Choose a deck with at least one card to study.');
                }
                $_SESSION['study_deck_id'] = (int)$deck['id'];
                $_SESSION['study_index'] = 0;
                $_SESSION['study_checked'] = false;
                $_SESSION['study_answer'] = '';
                $_SESSION['study_tutorial'] = $action === 'start_tutorial' ? ($deck['slug'] ?? '') : '';
                redirect_to('study');

            case 'reveal_answer':
                $study = current_study($pdo);
                if (!$study) {
                    throw new DomainException('Start a study session before checking an answer.');
                }
                $_SESSION['study_checked'] = true;
                $_SESSION['study_answer'] = post_string('answer');
                redirect_to('study');

            case 'rate_card':
                // Award XP for each rated card and streak/treat rewards on session completion.
                $study = current_study($pdo);
                $rating = post_string('rating');
                $pointsByRating = ['hard' => 5, 'good' => 15, 'easy' => 25];
                if (!$study || empty($_SESSION['study_checked']) || !isset($pointsByRating[$rating])) {
                    throw new DomainException('Reveal the answer before rating the card.');
                }
                // Lock shared progress before awarding XP to prevent lost updates.
                $pdo->beginTransaction();
                $state = $pdo->query('SELECT selected_pet FROM app_state WHERE id = 1 FOR UPDATE')->fetch();
                if (!$state) {
                    throw new RuntimeException('Vault state is not initialized. Import database.sql.');
                }
                award_xp($pdo, $state['selected_pet'], $pointsByRating[$rating]);
                $nextIndex = $study['index'] + 1;
                if ($nextIndex >= count($study['deck']['cards'])) {
                    // A streak advances at most once per calendar day and resets after a missed day.
                    $today = new DateTimeImmutable('today');
                    $state = $pdo->query('SELECT treats, streak, last_study_date FROM app_state WHERE id = 1 FOR UPDATE')->fetch();
                    $lastDate = $state['last_study_date'] ? new DateTimeImmutable($state['last_study_date']) : null;
                    $todayString = $today->format('Y-m-d');
                    $yesterdayString = $today->modify('-1 day')->format('Y-m-d');
                    $lastStudyDate = $lastDate?->format('Y-m-d');
                    $streak = $lastStudyDate === $yesterdayString
                        ? (int)$state['streak'] + 1
                        : ($lastStudyDate === $todayString ? (int)$state['streak'] : 1);
                    $complete = $pdo->prepare('UPDATE app_state SET treats = treats + 1, streak = ?, last_study_date = ? WHERE id = 1');
                    $complete->execute([$streak, $today->format('Y-m-d')]);
                    $pdo->commit();
                    unset($_SESSION['study_deck_id'], $_SESSION['study_index'], $_SESSION['study_checked'], $_SESSION['study_answer'], $_SESSION['study_tutorial']);
                    redirect_to('study', 'Session complete. Your companion earned 1 treat.');
                }
                $_SESSION['study_index'] = $nextIndex;
                $_SESSION['study_checked'] = false;
                $_SESSION['study_answer'] = '';
                $pdo->commit();
                redirect_to('study');

            case 'exit_study':
                unset($_SESSION['study_deck_id'], $_SESSION['study_index'], $_SESSION['study_checked'], $_SESSION['study_answer'], $_SESSION['study_tutorial']);
                redirect_to('study');

            case 'select_pet':
                $petId = post_string('companion_id');
                if (!isset($companions[$petId])) {
                    throw new DomainException('Choose a valid companion.');
                }
                $update = $pdo->prepare('UPDATE app_state SET selected_pet = ? WHERE id = 1');
                $update->execute([$petId]);
                redirect_to('pets', 'Active companion updated.');

            case 'feed_pet':
                $pdo->beginTransaction();
                $state = $pdo->query('SELECT treats, selected_pet FROM app_state WHERE id = 1 FOR UPDATE')->fetch();
                if (!$state || (int)$state['treats'] < 1) {
                    throw new DomainException('Complete a study session to earn a treat.');
                }
                $pdo->prepare('UPDATE app_state SET treats = treats - 1 WHERE id = 1')->execute();
                award_xp($pdo, $state['selected_pet'], 25);
                $pdo->commit();
                redirect_to('pets', 'Your companion gained 25 XP.');

            case 'import_backup':
                // Validate the entire file first; importing replaces decks and restores progress.
                $file = $_FILES['backup'] ?? null;
                if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || ($file['size'] ?? 0) >= 5_000_000) {
                    throw new DomainException('Choose a valid JSON backup smaller than 5 MB.');
                }
                try {
                    $backup = json_decode((string)file_get_contents($file['tmp_name']), true, 512, JSON_THROW_ON_ERROR);
                } catch (JsonException) {
                    throw new DomainException('The selected file is not valid JSON.');
                }
                if (!is_array($backup) || !is_array($backup['decks'] ?? null)) {
                    throw new DomainException('The selected file is not a valid Vault backup.');
                }
                if (count($backup['decks']) > 500) {
                    throw new DomainException('A backup can contain at most 500 decks.');
                }
                $preparedDecks = [];
                foreach ($backup['decks'] as $deck) {
                    if (!is_array($deck) || !is_string($deck['title'] ?? null) || trim($deck['title']) === '' || !is_array($deck['cards'] ?? null)) {
                        throw new DomainException('The backup contains a deck with an invalid format.');
                    }
                    $questions = [];
                    $answers = [];
                    foreach ($deck['cards'] as $card) {
                        if (!is_array($card) || !is_string($card['question'] ?? null) || !is_string($card['answer'] ?? null)) {
                            throw new DomainException('The backup contains a card with an invalid format.');
                        }
                        $questions[] = $card['question'];
                        $answers[] = $card['answer'];
                    }
                    $preparedDecks[] = [
                        'slug' => is_string($deck['slug'] ?? null) && in_array($deck['slug'], ['tutorial-basics', 'tutorial-streaks', 'tutorial-pets'], true)
                            ? $deck['slug']
                            : null,
                        'title' => mb_substr(trim($deck['title']), 0, 80),
                        'description' => is_string($deck['description'] ?? null) ? mb_substr($deck['description'], 0, 2000) : '',
                        'category' => is_string($deck['category'] ?? null) ? mb_substr($deck['category'], 0, 40) : 'Custom',
                        'cards' => validated_cards($questions, $answers),
                    ];
                }
                $backupState = is_array($backup['state'] ?? null) ? $backup['state'] : [];
                $backupPet = $backupState['selected_pet'] ?? 'ember';
                if (!is_string($backupPet) || !isset($companions[$backupPet])) {
                    $backupPet = 'ember';
                }
                $treats = isset($backupState['treats']) && is_numeric($backupState['treats'])
                    ? max(0, min(100000, (int)$backupState['treats']))
                    : 3;
                $streak = isset($backupState['streak']) && is_numeric($backupState['streak'])
                    ? max(0, min(100000, (int)$backupState['streak']))
                    : 1;
                $importedDate = is_string($backupState['last_study_date'] ?? null)
                    ? DateTimeImmutable::createFromFormat('!Y-m-d', $backupState['last_study_date'])
                    : false;
                $studyDate = $importedDate !== false
                    && $importedDate->format('Y-m-d') === ($backupState['last_study_date'] ?? null)
                    ? $backupState['last_study_date']
                    : null;
                $pdo->beginTransaction();
                $pdo->exec('DELETE FROM decks');
                $insertDeck = $pdo->prepare('INSERT INTO decks (slug, title, description, category) VALUES (?, ?, ?, ?)');
                foreach ($preparedDecks as $deck) {
                    $insertDeck->execute([
                        $deck['slug'],
                        $deck['title'],
                        $deck['description'],
                        $deck['category'],
                    ]);
                    $newDeckId = (int)$pdo->lastInsertId();
                    save_cards($pdo, $newDeckId, $deck['cards']);
                }
                $pdo->prepare('UPDATE app_state SET treats = ?, streak = ?, last_study_date = ?, selected_pet = ? WHERE id = 1')
                    ->execute([$treats, $streak, $studyDate, $backupPet]);
                $pdo->exec('UPDATE companion_progress SET level = 1, xp = 0');
                foreach (($backup['companions'] ?? []) as $pet) {
                    if (!is_array($pet) || !is_string($pet['companion_id'] ?? null) || !isset($companions[$pet['companion_id']])) {
                        continue;
                    }
                    $level = isset($pet['level']) && is_numeric($pet['level']) ? max(1, min(100000, (int)$pet['level'])) : 1;
                    $xp = isset($pet['xp']) && is_numeric($pet['xp']) ? max(0, min(99, (int)$pet['xp'])) : 0;
                    $pdo->prepare('UPDATE companion_progress SET level = ?, xp = ? WHERE companion_id = ?')
                        ->execute([$level, $xp, $pet['companion_id']]);
                }
                $pdo->commit();
                unset($_SESSION['study_deck_id'], $_SESSION['study_index'], $_SESSION['study_checked'], $_SESSION['study_answer'], $_SESSION['study_tutorial']);
                redirect_to('decks', 'Backup imported. Decks were replaced with the backup contents.');

            default:
                throw new DomainException('That action is not available.');
        }
    } catch (DomainException $error) {
        if ($pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        redirect_to($view, $error->getMessage(), 'error');
    } catch (Throwable $error) {
        if ($pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Vault request failed: ' . $error->getMessage());
        redirect_to($view, 'Vault could not complete that request. Check the server error log and database setup.', 'error');
    }
}

// Load the selected view and its data, using safe defaults when the database is unavailable.
$view = in_array($_GET['view'] ?? 'home', ['home', 'decks', 'study', 'pets'], true) ? $_GET['view'] : 'home';
$query = isset($_GET['q']) && is_string($_GET['q']) ? trim($_GET['q']) : '';
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$decks = [];
$state = ['treats' => 0, 'streak' => 0, 'last_study_date' => null, 'selected_pet' => 'ember'];
$progress = [];
$study = null;
$dbError = $databaseError;

if ($pdo instanceof PDO) {
    try {
        $state = $pdo->query('SELECT treats, streak, last_study_date, selected_pet FROM app_state WHERE id = 1')->fetch() ?: $state;
        $progressRows = $pdo->query('SELECT companion_id, level, xp FROM companion_progress')->fetchAll();
        foreach ($progressRows as $row) {
            $progress[$row['companion_id']] = ['level' => (int)$row['level'], 'xp' => (int)$row['xp']];
        }
        $sql = 'SELECT d.id, d.slug, d.title, d.description, d.category, COUNT(c.id) AS card_count FROM decks d LEFT JOIN cards c ON c.deck_id = d.id';
        $parameters = [];
        if ($query !== '') {
            $sql .= ' WHERE d.title LIKE ? OR d.description LIKE ?';
            $parameters = ['%' . $query . '%', '%' . $query . '%'];
        }
        $sql .= ' GROUP BY d.id ORDER BY d.created_at, d.id';
        $deckQuery = $pdo->prepare($sql);
        $deckQuery->execute($parameters);
        $decks = $deckQuery->fetchAll();
        $study = current_study($pdo);
    } catch (Throwable $error) {
        error_log('Vault page data load failed: ' . $error->getMessage());
        $dbError = 'Vault could not load its database records. Import database.sql and verify the configured database permissions.';
    }
}

$petId = isset($companions[$state['selected_pet'] ?? '']) ? $state['selected_pet'] : 'ember';
$activePet = $companions[$petId];
$petProgress = $progress[$petId] ?? ['level' => 1, 'xp' => 0];
$petLevel = $petProgress['level'];
$stageIndex = 0;
foreach ($stageLevels as $index => $requiredLevel) {
    if ($petLevel >= $requiredLevel) {
        $stageIndex = $index;
    }
}
$tutorialId = is_string($_SESSION['study_tutorial'] ?? null) ? $_SESSION['study_tutorial'] : '';
$tutorialSteps = $tutorialGuidance[$tutorialId] ?? null;
$studyAnswer = is_string($_SESSION['study_answer'] ?? null) ? $_SESSION['study_answer'] : '';
$answerChecked = !empty($_SESSION['study_checked']);
$answerCorrect = false;
if ($study && $answerChecked && $studyAnswer !== '') {
    $expected = mb_strtolower(trim($study['card']['answer']));
    $entered = mb_strtolower(trim($studyAnswer));
    $answerCorrect = mb_strpos($expected, $entered) !== false || mb_strpos($entered, $expected) !== false;
}
$sessionCount = $study ? count($study['deck']['cards']) : 0;
$sessionIndex = $study['index'] ?? 0;
$sessionPercent = $sessionCount > 0 ? (int)round(($sessionIndex / $sessionCount) * 100) : 0;

// Reuse one form for both creating a deck and editing an existing deck.
function render_deck_form(array $deck = []): void
{
    $isEdit = isset($deck['id']);
    $cards = $deck['cards'] ?? [];
    $slots = max(3, count($cards));
    ?>
    <form class="deck-editor" method="post" action="index.php?view=decks">
      <?= csrf_field() ?>
      <input type="hidden" name="view" value="decks">
      <input type="hidden" name="action" value="<?= $isEdit ? 'update_deck' : 'create_deck' ?>">
      <?php if ($isEdit): ?><input type="hidden" name="deck_id" value="<?= h($deck['id']) ?>"><?php endif; ?>
      <label class="form-field"><span>Deck title</span><input name="title" type="text" required maxlength="80" value="<?= h($deck['title'] ?? '') ?>" placeholder="e.g. Molecular Biology 101"></label>
      <label class="form-field"><span>Description</span><textarea name="description" rows="2" maxlength="2000" placeholder="Brief summary of concepts in this deck..."><?= h($deck['description'] ?? '') ?></textarea></label>
      <div class="cards-heading"><strong>Cards</strong><span>Add up to <?= h($slots) ?> complete cards in this form</span></div>
      <div class="form-cards">
        <?php for ($index = 0; $index < $slots; $index++): ?>
          <div class="form-card-row">
            <label class="visually-hidden" for="question-<?= $isEdit ? h($deck['id']) . '-' : '' ?><?= $index ?>">Question <?= $index + 1 ?></label>
            <textarea id="question-<?= $isEdit ? h($deck['id']) . '-' : '' ?><?= $index ?>" name="questions[]" maxlength="500" placeholder="Question prompt"><?= h($cards[$index]['question'] ?? '') ?></textarea>
            <label class="visually-hidden" for="answer-<?= $isEdit ? h($deck['id']) . '-' : '' ?><?= $index ?>">Answer <?= $index + 1 ?></label>
            <textarea id="answer-<?= $isEdit ? h($deck['id']) . '-' : '' ?><?= $index ?>" name="answers[]" maxlength="1200" placeholder="Expected answer"><?= h($cards[$index]['answer'] ?? '') ?></textarea>
          </div>
        <?php endfor; ?>
      </div>
      <div class="modal-actions"><button class="btn-primary" type="submit"><?= $isEdit ? 'Save Changes' : 'Create Deck' ?></button></div>
    </form>
    <?php
}
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#080c14">
    <meta name="description" content="Private active-recall flashcards and companion pet evolutions.">
    <title>Vault — Smart Flashcards &amp; Pet Evolutions</title>
    <link rel="stylesheet" href="style.css">
  </head>
  <body>
    <!-- Shared site header and navigation appear on every view. -->
    <header class="site-header">
      <div class="container header-inner">
        <a class="brand-wordmark" href="index.php?view=home" aria-label="Vault home">
          <span class="vault-door-logo" aria-hidden="true">
            <svg viewBox="0 0 36 36" fill="none">
              <circle cx="18" cy="18" r="16" fill="var(--surface)" stroke="var(--border)" stroke-width="2"></circle>
              <circle cx="18" cy="18" r="13" stroke="var(--primary)" stroke-width="1.5" stroke-dasharray="2 3" opacity=".7"></circle>
              <circle cx="18" cy="4" r="1.4" fill="var(--muted)"></circle><circle cx="18" cy="32" r="1.4" fill="var(--muted)"></circle>
              <circle cx="4" cy="18" r="1.4" fill="var(--muted)"></circle><circle cx="32" cy="18" r="1.4" fill="var(--muted)"></circle>
              <g class="vault-dial-wheel"><circle cx="18" cy="18" r="8.5" fill="var(--surface-raised)" stroke="var(--primary)" stroke-width="1.5"></circle>
                <path d="M18 9.5v17m-7.4-12.8 14.8 8.6m-14.8 0 14.8-8.6" stroke="var(--primary)" stroke-width="1.5" stroke-linecap="round"></path>
                <circle cx="18" cy="18" r="3.5" fill="var(--text)" stroke="var(--primary)" stroke-width="1"></circle><circle cx="18" cy="18" r="1.4" fill="var(--surface)"></circle>
              </g>
            </svg>
          </span>
          <span>Vault</span>
        </a>
        <nav class="desktop-nav" aria-label="Main navigation">
          <?php foreach (['home' => 'Home', 'decks' => 'Decks', 'study' => 'Study', 'pets' => 'Pets'] as $navView => $label): ?>
            <a class="nav-btn <?= $view === $navView ? 'active' : '' ?>" href="index.php?view=<?= h($navView) ?>"><?= h($label) ?></a>
          <?php endforeach; ?>
        </nav>
        <a class="btn-primary header-new-deck" href="index.php?view=decks#new-deck"><span class="plus">+</span> New Deck</a>
      </div>
    </header>

    <main class="container main-content">
      <?php if ($dbError !== ''): ?><div class="preview-notice error-notice" role="alert"><?= h($dbError) ?></div><?php endif; ?>
      <?php if (is_array($flash)): ?><div class="preview-notice <?= h($flash['type'] ?? 'success') ?>" role="<?= ($flash['type'] ?? '') === 'error' ? 'alert' : 'status' ?>"><?= h($flash['message'] ?? '') ?></div><?php endif; ?>

      <!-- Render one page section based on the validated view parameter. -->
      <?php if ($view === 'home'): ?>
        <section class="view-section active" id="view-home" aria-labelledby="home-title">
          <div class="hero">
            <p class="hero-eyebrow">PRIVATE RECALL &amp; HABIT SYSTEM</p>
            <h1 id="home-title">Retain what you learn.<br><span>Evolve companion pets.</span></h1>
            <p class="hero-description">Build flashcard decks with active recall, develop a steady study habit, and evolve companion pets as you learn. Your decks and progress are stored in MySQL.</p>
            <div class="hero-actions"><a class="btn-primary btn-large" href="index.php?view=study">Start Studying</a><a class="btn-secondary btn-large" href="index.php?view=decks#new-deck">Create a Deck</a></div>
          </div>
          <div class="stats-grid" aria-label="Your study stats">
            <article class="stat-card"><strong><?= count($decks) ?></strong><span>Active Decks</span></article>
            <?php $totalCards = array_sum(array_map(static fn(array $deck): int => (int)$deck['card_count'], $decks)); ?>
            <article class="stat-card"><strong><?= $totalCards ?></strong><span>Total Flashcards</span></article>
            <article class="stat-card"><strong><?= (int)$state['streak'] ?> <?= (int)$state['streak'] === 1 ? 'Day' : 'Days' ?></strong><span>Daily Study Streak</span></article>
            <article class="stat-card"><strong>Level <?= $petLevel ?></strong><span>Active Companion Level</span></article>
          </div>
          <section class="tutorial-section">
            <div class="section-intro"><h2>Interactive Guided Tutorials</h2><p>Each tutorial is a starter deck you can study and edit like your own.</p></div>
            <div class="tutorial-grid">
              <?php foreach ($decks as $deck): ?>
                <?php if ($deck['slug'] && isset($tutorialGuidance[$deck['slug']])): ?>
                  <article class="tutorial-card">
                    <h3><?= h($deck['title']) ?></h3><p><?= h($deck['description']) ?></p>
                    <div class="deck-meta"><span><?= (int)$deck['card_count'] ?> guided steps</span><span>·</span><span>Tutorial</span></div>
                    <form method="post" action="index.php?view=study"><?= csrf_field() ?><input type="hidden" name="view" value="study"><input type="hidden" name="action" value="start_tutorial"><input type="hidden" name="deck_id" value="<?= (int)$deck['id'] ?>"><button class="btn-primary btn-study-direct" type="submit">Start Tutorial</button></form>
                  </article>
                <?php endif; ?>
              <?php endforeach; ?>
            </div>
          </section>
        </section>

      <?php elseif ($view === 'decks'): ?>
        <section class="view-section active" id="view-decks" aria-labelledby="decks-title">
          <div class="page-heading"><div><h1 id="decks-title">Your Decks</h1><p>Create and manage flashcards saved in your MySQL database.</p></div>
            <form class="search-control" method="get" action="index.php"><input type="hidden" name="view" value="decks"><input name="q" type="search" value="<?= h($query) ?>" placeholder="Search decks..." aria-label="Search decks"><button class="btn-secondary" type="submit">Search</button></form>
          </div>
          <div class="deck-grid">
            <?php foreach ($decks as $deck): ?>
              <?php $fullDeck = load_deck($pdo, (int)$deck['id']); ?>
              <article class="deck-card">
                <h3><?= h($deck['title']) ?></h3><p><?= h($deck['description'] ?: 'A personal flashcard deck.') ?></p>
                <div class="deck-meta"><span><?= (int)$deck['card_count'] ?> <?= (int)$deck['card_count'] === 1 ? 'card' : 'cards' ?></span><span>·</span><span><?= h($deck['category']) ?></span></div>
                <div class="deck-card-actions">
                  <form method="post" action="index.php?view=study"><?= csrf_field() ?><input type="hidden" name="view" value="study"><input type="hidden" name="action" value="start_study"><input type="hidden" name="deck_id" value="<?= (int)$deck['id'] ?>"><button class="btn-primary" type="submit">Study</button></form>
                  <span class="spacer"></span><a class="btn-secondary edit-link" href="#edit-deck-<?= (int)$deck['id'] ?>">Edit</a>
                </div>
                <details class="deck-editor-details" id="edit-deck-<?= (int)$deck['id'] ?>"><summary>Edit deck and cards</summary>
                  <?php if ($fullDeck): render_deck_form($fullDeck); endif; ?>
                  <details class="delete-confirm"><summary>Delete this deck</summary><form method="post" action="index.php?view=decks"><?= csrf_field() ?><input type="hidden" name="view" value="decks"><input type="hidden" name="action" value="delete_deck"><input type="hidden" name="deck_id" value="<?= (int)$deck['id'] ?>"><p>This permanently deletes the deck and its cards.</p><button class="btn-danger" type="submit">Confirm Delete</button></form></details>
                </details>
              </article>
            <?php endforeach; ?>
          </div>
          <?php if ($decks === []): ?><div class="empty-state"><span class="empty-icon">⌕</span><h2>No decks found</h2><p><?= $query !== '' ? 'Try another search or create a deck.' : 'Create your first deck to get started.' ?></p></div><?php endif; ?>

          <section class="deck-create-section panel-card" id="new-deck">
            <h2>Create a New Deck</h2><p>Add a title and at least one complete question-and-answer card.</p>
            <?php render_deck_form(); ?>
          </section>
          <section class="backup-section panel-card">
            <h2>Data Backup</h2><p>Export the database content as JSON or replace your decks from a backup file.</p>
            <div class="backup-actions"><a class="btn-secondary" href="index.php?action=export">Export JSON Backup</a>
              <form method="post" action="index.php?view=decks" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="view" value="decks"><input type="hidden" name="action" value="import_backup"><label class="form-field"><span>Import JSON backup</span><input type="file" name="backup" accept="application/json,.json" required></label><button class="btn-secondary" type="submit">Import Backup</button></form>
            </div>
          </section>
        </section>

      <?php elseif ($view === 'study'): ?>
        <section class="view-section study-view active" id="view-study" aria-labelledby="study-title">
          <div class="page-heading"><div><h1 id="study-title"><?= $study ? h($study['deck']['title']) : 'Study' ?></h1><p><?= $study ? 'Use active recall, then rate how difficult the card felt.' : 'Choose a saved deck or start one of the guided tutorials.' ?></p></div></div>
          <?php if ($study): ?>
            <?php $currentCard = $study['card']; $isTutorial = $tutorialSteps !== null; ?>
            <div class="study-session">
              <div class="session-header"><div class="session-meta"><strong><?= h($study['deck']['title']) ?></strong><span class="separator">·</span><span>Card <?= $sessionIndex + 1 ?> of <?= $sessionCount ?></span></div>
                <form method="post" action="index.php?view=study"><?= csrf_field() ?><input type="hidden" name="view" value="study"><input type="hidden" name="action" value="exit_study"><button class="btn-secondary" type="submit">Exit Session</button></form>
              </div>
              <div class="session-progress" role="progressbar" aria-label="Study session progress" aria-valuemin="0" aria-valuemax="<?= $sessionCount ?>" aria-valuenow="<?= $sessionIndex ?>"><span style="width:<?= $sessionPercent ?>%"></span></div>
              <?php if ($isTutorial): ?><aside class="tutorial-coach"><strong>Guided step <?= $sessionIndex + 1 ?> of <?= $sessionCount ?></strong><p><?= h($tutorialSteps[$sessionIndex] ?? 'Try to recall the answer before checking.') ?></p></aside><?php endif; ?>
              <div class="flashcard <?= $answerChecked ? 'is-flipped' : '' ?>">
                <div class="flashcard-front"><span class="card-side-label">QUESTION</span><strong><?= h($currentCard['question']) ?></strong><span class="flip-hint">Try to recall the answer before revealing it</span></div>
                <?php if ($answerChecked): ?><div class="flashcard-back"><span class="card-side-label">EXPECTED ANSWER</span><strong><?= h($currentCard['answer']) ?></strong></div><?php endif; ?>
              </div>
              <?php if (!$answerChecked): ?>
                <form class="answer-tools" method="post" action="index.php?view=study"><?= csrf_field() ?><input type="hidden" name="view" value="study"><input type="hidden" name="action" value="reveal_answer"><input name="answer" type="text" maxlength="1200" placeholder="Type your answer for active recall..." aria-label="Type your answer for active recall"><button class="btn-primary" type="submit">Check Answer</button></form>
              <?php else: ?>
                <p class="answer-feedback <?= $answerCorrect ? 'correct' : 'incorrect' ?>"><?= $studyAnswer === '' ? 'Compare with the answer, then choose a rating.' : ($answerCorrect ? 'Good recall. Compare your answer with the example.' : 'Review the example and strengthen your recall.') ?></p>
                <div class="rating-grid">
                  <?php foreach (['hard' => 5, 'good' => 15, 'easy' => 25] as $rating => $points): ?><form method="post" action="index.php?view=study"><?= csrf_field() ?><input type="hidden" name="view" value="study"><input type="hidden" name="action" value="rate_card"><input type="hidden" name="rating" value="<?= h($rating) ?>"><button class="btn-rating <?= h($rating) ?>" type="submit"><strong><?= ucfirst($rating) ?></strong><span>+<?= $points ?> XP</span></button></form><?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>
          <?php else: ?>
            <div class="study-picker panel-card">
              <div class="study-book-icon" aria-hidden="true"><svg viewBox="0 0 32 32"><path d="M8 5h16v22H8a3 3 0 0 1-3-3V8a3 3 0 0 1 3-3Zm0 0v19a3 3 0 0 0-3 3m7-16h8m-8 5h8"></path></svg></div>
              <h2>Select a Deck to Study</h2><p>Select which deck you would like to review.</p>
              <?php if ($decks !== []): ?><form method="post" action="index.php?view=study"><?= csrf_field() ?><input type="hidden" name="view" value="study"><input type="hidden" name="action" value="start_study"><label class="select-wrap"><span class="visually-hidden">Choose a study deck</span><select name="deck_id" required><?php foreach ($decks as $deck): ?><option value="<?= (int)$deck['id'] ?>"><?= h($deck['title']) ?> (<?= (int)$deck['card_count'] ?> cards)</option><?php endforeach; ?></select></label><button class="btn-primary btn-large start-session-btn" type="submit">Start Session</button></form><?php else: ?><p>Create a deck with at least one card before studying.</p><a class="btn-primary" href="index.php?view=decks#new-deck">Create a Deck</a><?php endif; ?>
              <div class="study-tutorial-links"><?php foreach ($decks as $deck): if ($deck['slug'] && isset($tutorialGuidance[$deck['slug']])): ?><form method="post" action="index.php?view=study"><?= csrf_field() ?><input type="hidden" name="view" value="study"><input type="hidden" name="action" value="start_tutorial"><input type="hidden" name="deck_id" value="<?= (int)$deck['id'] ?>"><button class="btn-secondary" type="submit">Start <?= h(str_replace('Tutorial ', '', strtok($deck['title'], ':'))) ?> Tutorial</button></form><?php endif; endforeach; ?></div>
            </div>
          <?php endif; ?>
        </section>

      <?php else: ?>
        <section class="view-section active" id="view-pets" aria-labelledby="pets-title">
          <div class="page-heading pets-heading"><div><h1 id="pets-title">Companion Pets</h1><p>Earn treats through study and evolve companions through five milestones.</p></div><div class="treat-counter">Treats Available: <strong><?= (int)$state['treats'] ?></strong></div></div>
          <section class="pet-hero panel-card">
            <div class="pet-avatar" aria-hidden="true"><?= h($activePet['emoji']) ?></div>
            <div class="pet-info"><h2><?= h($activePet['name']) ?></h2><p class="pet-stage">Stage <?= $stageIndex + 1 ?>: <?= h($activePet['stages'][$stageIndex]) ?></p>
              <p class="pet-level"><span>Level <?= $petLevel ?></span><span>·</span><span><?= $petProgress['xp'] ?> / 100 XP</span></p><div class="xp-track" role="progressbar" aria-label="Pet experience" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $petProgress['xp'] ?>"><span style="width:<?= $petProgress['xp'] ?>%"></span></div>
            </div>
            <div class="pet-feed"><form method="post" action="index.php?view=pets"><?= csrf_field() ?><input type="hidden" name="view" value="pets"><input type="hidden" name="action" value="feed_pet"><button class="btn-primary btn-large" type="submit" <?= (int)$state['treats'] < 1 ? 'disabled' : '' ?>>✦ Feed 1 Treat (+25 XP)</button></form><p>Complete a study session to earn treats.</p></div>
          </section>
          <section class="evolution-section"><h2>5 Evolution Stages</h2><div class="evolution-grid">
            <?php foreach ($activePet['stages'] as $index => $stage): $unlocked = $petLevel >= $stageLevels[$index]; ?>
              <article class="evolution-card <?= $unlocked ? 'unlocked' : 'locked' ?>"><div class="evolution-art" aria-hidden="true"><?= $index === 0 ? h($activePet['emoji']) : ($index === 1 ? '🐾' : '✦') ?></div><strong><?= $unlocked ? h($stage) : '???' ?></strong><span><?= $unlocked ? 'Unlocked' : 'Unlocks at Lv ' . $stageLevels[$index] ?></span></article>
            <?php endforeach; ?>
          </div></section>
          <section class="companions-section"><h2>Choose Companion</h2><div class="companion-grid">
            <?php foreach ($companions as $id => $companion): $companionProgress = $progress[$id] ?? ['level' => 1, 'xp' => 0]; ?>
              <form class="companion-form" method="post" action="index.php?view=pets"><?= csrf_field() ?><input type="hidden" name="view" value="pets"><input type="hidden" name="action" value="select_pet"><input type="hidden" name="companion_id" value="<?= h($id) ?>"><button class="companion-card <?= $id === $petId ? 'active' : '' ?>" type="submit" aria-pressed="<?= $id === $petId ? 'true' : 'false' ?>"><span class="companion-emoji" aria-hidden="true"><?= h($companion['emoji']) ?></span><span><span class="companion-name"><?= h($companion['name']) ?></span><span class="companion-meta">Level <?= $companionProgress['level'] ?> · <?= h($companion['element']) ?></span></span></button></form>
            <?php endforeach; ?>
          </div></section>
        </section>
      <?php endif; ?>
    </main>
  </body>
</html>
