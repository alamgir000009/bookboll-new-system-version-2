/*
  # Add anon RLS policies for classes, sessions, subscriptions, and trial agreements

  The app uses the anon key without an auth session (admin tool pattern).
  All four tables currently only allow `authenticated` role, causing silent
  insert/update/delete failures. This mirrors the pattern already used on
  booking_payments (anon_insert_booking_payments etc.).

  Tables updated:
  - class_programs
  - class_sessions
  - class_subscriptions
  - provider_trial_agreements
*/

-- class_programs
CREATE POLICY "anon_select_class_programs"
  ON class_programs FOR SELECT TO anon USING (true);

CREATE POLICY "anon_insert_class_programs"
  ON class_programs FOR INSERT TO anon WITH CHECK (true);

CREATE POLICY "anon_update_class_programs"
  ON class_programs FOR UPDATE TO anon USING (true) WITH CHECK (true);

CREATE POLICY "anon_delete_class_programs"
  ON class_programs FOR DELETE TO anon USING (true);

-- class_sessions
CREATE POLICY "anon_select_class_sessions"
  ON class_sessions FOR SELECT TO anon USING (true);

CREATE POLICY "anon_insert_class_sessions"
  ON class_sessions FOR INSERT TO anon WITH CHECK (true);

CREATE POLICY "anon_update_class_sessions"
  ON class_sessions FOR UPDATE TO anon USING (true) WITH CHECK (true);

CREATE POLICY "anon_delete_class_sessions"
  ON class_sessions FOR DELETE TO anon USING (true);

-- class_subscriptions
CREATE POLICY "anon_select_class_subscriptions"
  ON class_subscriptions FOR SELECT TO anon USING (true);

CREATE POLICY "anon_insert_class_subscriptions"
  ON class_subscriptions FOR INSERT TO anon WITH CHECK (true);

CREATE POLICY "anon_update_class_subscriptions"
  ON class_subscriptions FOR UPDATE TO anon USING (true) WITH CHECK (true);

CREATE POLICY "anon_delete_class_subscriptions"
  ON class_subscriptions FOR DELETE TO anon USING (true);

-- provider_trial_agreements
CREATE POLICY "anon_select_provider_trial_agreements"
  ON provider_trial_agreements FOR SELECT TO anon USING (true);

CREATE POLICY "anon_insert_provider_trial_agreements"
  ON provider_trial_agreements FOR INSERT TO anon WITH CHECK (true);
