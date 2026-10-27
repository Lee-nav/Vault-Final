-- Select the target database in your hosting panel or MySQL client before import.
-- Deck metadata; cards are stored separately so each deck can have many cards.
CREATE TABLE IF NOT EXISTS decks (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug VARCHAR(80) NULL,
  title VARCHAR(80) NOT NULL,
  description TEXT NOT NULL,
  category VARCHAR(40) NOT NULL DEFAULT 'Custom',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_decks_slug (slug)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS cards (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  deck_id BIGINT UNSIGNED NOT NULL,
  question VARCHAR(500) NOT NULL,
  answer VARCHAR(1200) NOT NULL,
  position SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_cards_deck_position (deck_id, position),
  CONSTRAINT fk_cards_deck FOREIGN KEY (deck_id)
    REFERENCES decks (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Shared study streak, treat balance, and currently selected companion.
CREATE TABLE IF NOT EXISTS app_state (
  id TINYINT UNSIGNED NOT NULL,
  treats INT UNSIGNED NOT NULL DEFAULT 3,
  streak INT UNSIGNED NOT NULL DEFAULT 1,
  last_study_date DATE NULL,
  selected_pet VARCHAR(24) NOT NULL DEFAULT 'ember',
  PRIMARY KEY (id),
  CONSTRAINT chk_app_state_singleton CHECK (id = 1)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS companion_progress (
  companion_id VARCHAR(24) NOT NULL,
  level INT UNSIGNED NOT NULL DEFAULT 1,
  xp SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (companion_id)
) ENGINE=InnoDB;

-- Add the initial singleton state and one progress row for each companion.
INSERT IGNORE INTO app_state (id, treats, streak, last_study_date, selected_pet)
VALUES (1, 3, 1, NULL, 'ember');

INSERT IGNORE INTO companion_progress (companion_id, level, xp)
VALUES
  ('ember', 1, 0),
  ('aqua', 1, 0),
  ('terra', 1, 0),
  ('zephyr', 1, 0);

-- Install the three guided starter decks, without duplicating existing decks.
INSERT IGNORE INTO decks (slug, title, description, category)
VALUES
  ('tutorial-basics', 'Tutorial 1: Vault Basics & Creation', 'Learn how decks work, create your first one, and practice active recall.', 'Tutorial'),
  ('tutorial-streaks', 'Tutorial 2: Daily Streaks & Habit', 'Discover how daily study streaks work and why consistency beats cramming.', 'Tutorial'),
  ('tutorial-pets', 'Tutorial 3: Pets & 5-Tier Evolutions', 'Earn treats, level up companion pets, and unlock mysterious evolution tiers.', 'Tutorial');

INSERT INTO cards (deck_id, question, answer, position)
SELECT d.id, c.question, c.answer, c.position
FROM decks d
JOIN (
  SELECT 'tutorial-basics' AS slug, 'What is a deck in Vault?' AS question, 'A deck is a collection of question-and-answer flashcards grouped around a topic.' AS answer, 1 AS position
  UNION ALL SELECT 'tutorial-basics', 'How do you create a deck?', 'Choose New Deck, enter a title, add at least one complete question-and-answer card, then save.', 2
  UNION ALL SELECT 'tutorial-basics', 'How should you study a flashcard?', 'Try to recall the answer before revealing it, then rate how difficult it was.', 3
  UNION ALL SELECT 'tutorial-streaks', 'How does a daily study streak increase?', 'By completing at least one study session on consecutive calendar days.', 1
  UNION ALL SELECT 'tutorial-streaks', 'Can several sessions add several streak days?', 'No. Streaks advance by calendar day.', 2
  UNION ALL SELECT 'tutorial-streaks', 'What happens if you miss a day?', 'Your streak starts over when you study again.', 3
  UNION ALL SELECT 'tutorial-pets', 'How do you earn pet treats?', 'Complete a study session to earn a treat.', 1
  UNION ALL SELECT 'tutorial-pets', 'At what levels do pets evolve?', 'Pets evolve at levels 1, 5, 10, 15, and 20.', 2
  UNION ALL SELECT 'tutorial-pets', 'How are locked evolutions displayed?', 'As mysterious silhouettes until you reach the required level.', 3
) c ON c.slug = d.slug
WHERE NOT EXISTS (
  SELECT 1 FROM cards existing
  WHERE existing.deck_id = d.id AND existing.position = c.position
);
