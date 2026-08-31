export default function FieldError({ errors }) {
  if (!errors?.length) return null;
  return <p className="field-error">{errors.join(" ")}</p>;
}

