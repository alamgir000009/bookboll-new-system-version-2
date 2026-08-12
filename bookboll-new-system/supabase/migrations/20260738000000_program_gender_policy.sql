-- Gender policy for membership programs — who can join: 'mixed' (default) | 'men' | 'women'.
-- A separate dimension from `audience` (age targeting for class/academy, access for gyms);
-- shown as a tag on customer program cards and detail pages.
alter table class_programs add column if not exists gender_policy text not null default 'mixed';

comment on column class_programs.gender_policy is 'Who can join: mixed | men | women. Shown as a tag on customer cards & detail pages.';
