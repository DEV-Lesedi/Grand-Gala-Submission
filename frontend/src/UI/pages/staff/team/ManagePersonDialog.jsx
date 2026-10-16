import { useState } from 'react'
import Button from '../../../components/Button'
import Modal from '../../../components/Modal'
import { rolesICanGive } from './teamRules'
import { STAFF_ROLES } from '../../../../lib/statuses'
import '../spaces/spaceForms.css'
import './ManagePersonDialog.css'
import Select from '../../../components/Select'

// person: the account · me: who is signed in
// onUpdate(changes) and onResetPassword() → the API's error message, or '' once done
export default function ManagePersonDialog({ person, me, onUpdate, onResetPassword, onClose }) {
  const [role, setRole] = useState(person.role)
  const roles = rolesICanGive(me) // an admin can only give "Staff", so no Role section for them
  const [message, setMessage] = useState({ text: '', isError: false })

  // Changing a role also signs them out everywhere, so their new access applies straight away
  async function saveRole() {
    const problem = await onUpdate({ role })
    if (problem) return setMessage({ text: problem, isError: true })
    setMessage({ text: `${person.name} is now ${role === 'admin' ? 'an admin' : 'staff'}.`, isError: false })
  }

  async function resetPassword() {
    const problem = await onResetPassword()
    if (problem) return setMessage({ text: problem, isError: true })
    setMessage({ text: `We emailed ${person.name} a link to choose a new password. It works for 60 minutes.`, isError: false })
  }

  // Switching off also ends their open sessions
  async function switchAccess() {
    const problem = await onUpdate({ isActive: !person.isActive })
    setMessage({ text: problem, isError: Boolean(problem) })
  }

  return (
    <Modal title={`Manage ${person.name} ${person.surname}`} subtitle={person.email} onClose={onClose}>
      <div className="space-form manage-person">
        {roles.length > 1 && (
          <section className="manage-section">
            <h3 className="manage-heading">Role</h3>
            <div className="manage-row">
              <label className="space-form-field">
                Role
                <Select value={role} onChange={(event) => setRole(event.target.value)}>
                  {roles.map((option) => (
                    <option key={option} value={option}>
                      {STAFF_ROLES[option].label}
                    </option>
                  ))}
                </Select>
              </label>
              <Button variant="secondary" onClick={saveRole} disabled={role === person.role}>
                Save role
              </Button>
            </div>
          </section>
        )}

        <section className="manage-section">
          <h3 className="manage-heading">Password</h3>
          <div className="manage-row">
            <p>We email {person.name} a link to choose a new password.</p>
            <Button variant="secondary" onClick={resetPassword}>
              Email a reset link
            </Button>
          </div>
        </section>

        <section className="manage-section">
          <h3 className="manage-heading">Access</h3>
          <div className="manage-row manage-access">
            <p>{person.isActive ? `${person.name} can sign in.` : `Switched off: ${person.name} can’t sign in.`}</p>
            {person.isActive ? (
              <Button className="btn-danger" onClick={switchAccess}>
                Switch off
              </Button>
            ) : (
              <Button onClick={switchAccess}>Switch on</Button>
            )}
          </div>
        </section>

        {message.text && <p className={message.isError ? 'space-form-error' : 'manage-done'}>{message.text}</p>}
      </div>
    </Modal>
  )
}
