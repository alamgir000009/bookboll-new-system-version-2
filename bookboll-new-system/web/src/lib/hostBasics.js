export function validateHostBasics(form, type = 'game') {
  const errors = {}
  if (!String(form.title || '').trim()) errors.title = type === 'tournament' ? 'Give your tournament a name.' : type === 'event' ? 'Give your hangout a title.' : 'Give your game a title.'
  if (type !== 'event' && !String(form.sport || '').trim()) errors.sport = 'Choose or type a sport.'
  if (type !== 'tournament') {
    // Blank by default: the host types their own capacity, so an empty field is
    // "not answered yet", not "too small".
    if (String(form.max_players ?? '').trim() === '') errors.max_players = type === 'event' ? 'Enter how many people can join.' : 'Enter how many players can join.'
    else if (!Number.isInteger(Number(form.max_players)) || Number(form.max_players) < 2) errors.max_players = 'Allow at least 2 people to join.'
  }
  if (!Number.isFinite(Number(form.price_per_person)) || Number(form.price_per_person) < 0) errors.price_per_person = 'Enter a price of 0 or more.'
  return errors
}
