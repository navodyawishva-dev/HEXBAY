import { useEffect, useState } from "react";
import { apiRequest } from "../../api/client";
import { useAuth } from "../../contexts/AuthContext";
import Modal from "../../components/Modal";

const emptyCategory = {
  name: "",
  slug: "",
  description: "",
  parent_id: "",
  sort_order: 0,
  is_active: true,
  requires_listing_approval: true,
};

const emptySpecification = {
  code: "",
  display_name: "",
  data_type: "text",
  unit: "",
  minimum_value: "",
  maximum_value: "",
  sort_order: 0,
  is_required: false,
  is_filterable: true,
  is_compatibility_field: false,
  is_active: true,
  option_text: "",
};

export default function AdminCatalogPage() {
  const { token } = useAuth();
  const [categories, setCategories] = useState([]);
  const [selectedId, setSelectedId] = useState(null);
  const [specifications, setSpecifications] = useState([]);
  const [categoryEditor, setCategoryEditor] = useState(null);
  const [specificationEditor, setSpecificationEditor] = useState(null);
  const [message, setMessage] = useState("");

  const loadCategories = () =>
    apiRequest("/admin/categories", { token }).then((response) => {
      const items = response.data.categories;
      setCategories(items);
      setSelectedId((current) => current ?? (items[0] ? Number(items[0].id) : null));
    });

  const loadSpecifications = (categoryId) => {
    if (!categoryId) {
      setSpecifications([]);
      return Promise.resolve();
    }
    return apiRequest(`/admin/categories/${categoryId}/specifications`, { token }).then(
      (response) => setSpecifications(response.data.specifications),
    );
  };

  useEffect(() => {
    loadCategories().catch((error) => setMessage(error.message));
  }, [token]);

  useEffect(() => {
    loadSpecifications(selectedId).catch((error) => setMessage(error.message));
  }, [selectedId, token]);

  const selectedCategory = categories.find(
    (category) => Number(category.id) === Number(selectedId),
  );

  const saveCategory = async () => {
    setMessage("");
    try {
      const path = categoryEditor.id
        ? `/admin/categories/${categoryEditor.id}`
        : "/admin/categories";
      const response = await apiRequest(path, {
        method: "POST",
        token,
        body: {
          ...categoryEditor,
          parent_id: categoryEditor.parent_id || null,
        },
      });
      setSelectedId(Number(response.data.category.id));
      setCategoryEditor(null);
      setMessage("Category saved successfully.");
      await loadCategories();
    } catch (error) {
      setMessage(error.message);
    }
  };

  const saveSpecification = async () => {
    setMessage("");
    try {
      const isOption = ["option", "multi_option"].includes(
        specificationEditor.data_type,
      );
      const options = isOption
        ? specificationEditor.option_text
            .split(",")
            .map((value) => value.trim())
            .filter(Boolean)
            .map((displayValue) => ({ display_value: displayValue }))
        : [];
      const path = specificationEditor.id
        ? `/admin/categories/${selectedId}/specifications/${specificationEditor.id}`
        : `/admin/categories/${selectedId}/specifications`;
      await apiRequest(path, {
        method: "POST",
        token,
        body: { ...specificationEditor, options },
      });
      setSpecificationEditor(null);
      setMessage("Specification definition saved.");
      await loadSpecifications(selectedId);
      await loadCategories();
    } catch (error) {
      setMessage(error.message);
    }
  };

  const editSpecification = (specification) =>
    setSpecificationEditor({
      ...emptySpecification,
      ...specification,
      option_text: (specification.options ?? [])
        .filter((option) => Boolean(Number(option.is_active)))
        .map((option) => option.display_value)
        .join(", "),
    });

  return (
    <>
      <div className="admin-page-heading">
        <div>
          <span className="section-kicker">Catalogue structure</span>
          <h1>Categories and specifications</h1>
          <p>
            Define consistent product fields before sellers create listings.
          </p>
        </div>
        <button
          className="button button-primary"
          onClick={() => setCategoryEditor({ ...emptyCategory })}
        >
          Add category
        </button>
      </div>

      {message && <div className="alert alert-info">{message}</div>}

      <div className="catalog-admin-grid">
        <section className="admin-panel category-admin-panel">
          <div className="panel-heading">
            <div>
              <h2>Product categories</h2>
              <p>{categories.length} categories configured</p>
            </div>
          </div>
          <div className="admin-choice-list">
            {categories.map((category) => (
              <button
                className={
                  Number(selectedId) === Number(category.id) ? "active" : ""
                }
                key={category.id}
                onClick={() => setSelectedId(Number(category.id))}
              >
                <span className="choice-icon">{category.name.slice(0, 2)}</span>
                <span>
                  <strong>{category.name}</strong>
                  <small>
                    {category.specification_count} fields ·{" "}
                    {Boolean(Number(category.is_active)) ? "Active" : "Inactive"}
                  </small>
                </span>
              </button>
            ))}
          </div>
        </section>

        <section className="admin-panel specification-panel">
          {selectedCategory ? (
            <>
              <div className="panel-heading">
                <div>
                  <span className="section-kicker">Selected category</span>
                  <h2>{selectedCategory.name}</h2>
                  <p>{selectedCategory.description || "No description provided."}</p>
                </div>
                <div className="panel-actions">
                  <button
                    className="button button-ghost"
                    onClick={() =>
                      setCategoryEditor({
                        ...selectedCategory,
                        parent_id: selectedCategory.parent_id ?? "",
                        is_active: Boolean(Number(selectedCategory.is_active)),
                        requires_listing_approval: Boolean(
                          Number(selectedCategory.requires_listing_approval),
                        ),
                      })
                    }
                  >
                    Edit category
                  </button>
                  <button
                    className="button button-dark"
                    onClick={() =>
                      setSpecificationEditor({ ...emptySpecification })
                    }
                  >
                    Add field
                  </button>
                </div>
              </div>
              <div className="specification-list">
                {specifications.length === 0 ? (
                  <div className="compact-empty">
                    No structured fields yet. Add the first one for this category.
                  </div>
                ) : (
                  specifications.map((specification) => (
                    <button
                      className="specification-row"
                      key={specification.id}
                      onClick={() => editSpecification(specification)}
                    >
                      <span>
                        <strong>{specification.display_name}</strong>
                        <small>{specification.code}</small>
                      </span>
                      <span className="specification-tags">
                        <em>{specification.data_type.replace("_", " ")}</em>
                        {Boolean(Number(specification.is_required)) && (
                          <em>Required</em>
                        )}
                        {Boolean(Number(specification.is_compatibility_field)) && (
                          <em>Compatibility</em>
                        )}
                      </span>
                    </button>
                  ))
                )}
              </div>
            </>
          ) : (
            <div className="compact-empty">Create a category to begin.</div>
          )}
        </section>
      </div>

      {categoryEditor && (
        <Modal onClose={() => setCategoryEditor(null)} ariaLabel="Edit catalogue category">
            <span className="section-kicker">Catalogue category</span>
            <h2>{categoryEditor.id ? "Edit category" : "Create category"}</h2>
            <div className="form-grid">
              <label>
                Category name
                <input
                  value={categoryEditor.name}
                  onChange={(event) =>
                    setCategoryEditor({ ...categoryEditor, name: event.target.value })
                  }
                />
              </label>
              <label>
                URL slug
                <input
                  value={categoryEditor.slug}
                  placeholder="Generated from the name"
                  onChange={(event) =>
                    setCategoryEditor({ ...categoryEditor, slug: event.target.value })
                  }
                />
              </label>
              <label>
                Parent category
                <select
                  value={categoryEditor.parent_id ?? ""}
                  onChange={(event) =>
                    setCategoryEditor({
                      ...categoryEditor,
                      parent_id: event.target.value,
                    })
                  }
                >
                  <option value="">No parent</option>
                  {categories
                    .filter(
                      (category) =>
                        Number(category.id) !== Number(categoryEditor.id),
                    )
                    .map((category) => (
                      <option value={category.id} key={category.id}>
                        {category.name}
                      </option>
                    ))}
                </select>
              </label>
              <label>
                Sort order
                <input
                  type="number"
                  min="0"
                  value={categoryEditor.sort_order}
                  onChange={(event) =>
                    setCategoryEditor({
                      ...categoryEditor,
                      sort_order: Number(event.target.value),
                    })
                  }
                />
              </label>
              <label className="full-width">
                Description
                <textarea
                  rows="3"
                  value={categoryEditor.description}
                  onChange={(event) =>
                    setCategoryEditor({
                      ...categoryEditor,
                      description: event.target.value,
                    })
                  }
                />
              </label>
            </div>
            <label className="check-row">
              <input
                type="checkbox"
                checked={categoryEditor.is_active}
                onChange={(event) =>
                  setCategoryEditor({
                    ...categoryEditor,
                    is_active: event.target.checked,
                  })
                }
              />
              Category is visible to marketplace users
            </label>
            <label className="check-row">
              <input
                type="checkbox"
                checked={categoryEditor.requires_listing_approval}
                onChange={(event) =>
                  setCategoryEditor({
                    ...categoryEditor,
                    requires_listing_approval: event.target.checked,
                  })
                }
              />
              New listings require administrator approval
            </label>
            <div className="modal-actions">
              <button
                className="button button-ghost"
                onClick={() => setCategoryEditor(null)}
              >
                Cancel
              </button>
              <button className="button button-primary" onClick={saveCategory}>
                Save category
              </button>
            </div>
        </Modal>
      )}

      {specificationEditor && (
        <Modal
          onClose={() => setSpecificationEditor(null)}
          className="wide-modal"
          ariaLabel="Edit catalogue specification"
        >
            <span className="section-kicker">Structured product field</span>
            <h2>
              {specificationEditor.id ? "Edit specification" : "Add specification"}
            </h2>
            <div className="form-grid">
              <label>
                Display name
                <input
                  value={specificationEditor.display_name}
                  placeholder="CPU socket"
                  onChange={(event) =>
                    setSpecificationEditor({
                      ...specificationEditor,
                      display_name: event.target.value,
                    })
                  }
                />
              </label>
              <label>
                Field code
                <input
                  value={specificationEditor.code}
                  placeholder="cpu_socket"
                  onChange={(event) =>
                    setSpecificationEditor({
                      ...specificationEditor,
                      code: event.target.value,
                    })
                  }
                />
              </label>
              <label>
                Data type
                <select
                  value={specificationEditor.data_type}
                  onChange={(event) =>
                    setSpecificationEditor({
                      ...specificationEditor,
                      data_type: event.target.value,
                    })
                  }
                >
                  <option value="text">Text</option>
                  <option value="integer">Whole number</option>
                  <option value="decimal">Decimal number</option>
                  <option value="boolean">Yes / No</option>
                  <option value="option">Controlled option</option>
                  <option value="multi_option">Multiple options</option>
                </select>
              </label>
              <label>
                Unit
                <input
                  value={specificationEditor.unit ?? ""}
                  placeholder="GB, W, mm"
                  onChange={(event) =>
                    setSpecificationEditor({
                      ...specificationEditor,
                      unit: event.target.value,
                    })
                  }
                />
              </label>
              {["integer", "decimal"].includes(specificationEditor.data_type) && (
                <>
                  <label>
                    Minimum
                    <input
                      type="number"
                      value={specificationEditor.minimum_value ?? ""}
                      onChange={(event) =>
                        setSpecificationEditor({
                          ...specificationEditor,
                          minimum_value: event.target.value,
                        })
                      }
                    />
                  </label>
                  <label>
                    Maximum
                    <input
                      type="number"
                      value={specificationEditor.maximum_value ?? ""}
                      onChange={(event) =>
                        setSpecificationEditor({
                          ...specificationEditor,
                          maximum_value: event.target.value,
                        })
                      }
                    />
                  </label>
                </>
              )}
              {["option", "multi_option"].includes(
                specificationEditor.data_type,
              ) && (
                <label className="full-width">
                  Allowed options
                  <textarea
                    rows="3"
                    value={specificationEditor.option_text}
                    placeholder="DDR4, DDR5"
                    onChange={(event) =>
                      setSpecificationEditor({
                        ...specificationEditor,
                        option_text: event.target.value,
                      })
                    }
                  />
                  <small>Separate each controlled value with a comma.</small>
                </label>
              )}
            </div>
            <div className="check-grid">
              {[
                ["is_required", "Required on seller forms"],
                ["is_filterable", "Available as a catalogue filter"],
                ["is_compatibility_field", "Used by compatibility rules"],
                ["is_active", "Field is active"],
              ].map(([field, label]) => (
                <label className="check-row" key={field}>
                  <input
                    type="checkbox"
                    checked={Boolean(Number(specificationEditor[field]))}
                    onChange={(event) =>
                      setSpecificationEditor({
                        ...specificationEditor,
                        [field]: event.target.checked,
                      })
                    }
                  />
                  {label}
                </label>
              ))}
            </div>
            <div className="modal-actions">
              <button
                className="button button-ghost"
                onClick={() => setSpecificationEditor(null)}
              >
                Cancel
              </button>
              <button className="button button-primary" onClick={saveSpecification}>
                Save specification
              </button>
            </div>
        </Modal>
      )}
    </>
  );
}
