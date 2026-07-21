/// PDO/MySQL liefert Durchschnittswerte (z.B. AVG(rating)) haeufig als
/// String statt als Zahl (Praezisionserhalt), daher reicht ein einfaches
/// `as num?` beim JSON-Parsen nicht aus - das wuerde bei jedem Eintrag mit
/// mindestens einer Bewertung eine TypeError werfen.
double? parseNullableDouble(dynamic value) {
  if (value == null) return null;
  if (value is num) return value.toDouble();
  return double.tryParse(value.toString());
}

int parseIntOrZero(dynamic value) {
  if (value == null) return 0;
  if (value is num) return value.toInt();
  return int.tryParse(value.toString()) ?? 0;
}
