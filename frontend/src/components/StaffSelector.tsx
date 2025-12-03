import { useState } from "react";
import { Group, Avatar, Text, Loader, Badge, Flex, CloseButton, Combobox, useCombobox, Pill, PillsInput, Tooltip, Button } from '@mantine/core';
import { IconAlertSquare, IconUser, IconUsers } from '@tabler/icons-react';
import { fetchData } from "../utils";
import { DecordatedUser, User } from "../types/types";
import { useStateStore } from "../stores/stateStore";

type Props = {
  staff: User[],
  setStaff: (value: User[]) => void,
  label: string,
  sublabel?: string,
  tip?: string,
  multiple: boolean,
  readOnly: boolean,
}

export function StaffSelector({staff, setStaff, label, sublabel, tip, multiple, readOnly}: Props) {

  const [search, setSearch] = useState('');
  const [isLoading, setIsLoading] = useState(false);
  const [searchResults, setSearchResults] = useState<DecordatedUser[]>([]);

  const combobox = useCombobox({
    onDropdownClose: () => combobox.resetSelectedOption(),
    onDropdownOpen: () => combobox.updateSelectedOptionIndex('active'),
  });
  
  const decorateStaff = (item: User): DecordatedUser => ({
    value: { un: item.un, fn: item.fn, ln: item.ln }, // What we'll send to the server for saving.
    label: item.fn + " " + item.ln,
    username: item.un,
    image: '/local/activities/avatar.php?username=' + item.un
  })

  const loadStaff = async (query: string) => {
    setSearch(query)
    combobox.updateSelectedOptionIndex();
    combobox.openDropdown();
    if (!query.length) {
      setSearchResults([])
      return;
    }
    setIsLoading(true);
    const response = await fetchData({
      query: {
        methodname: 'local_activities-search_staff',
        query: query,
      }
    })
    const data = response.data.map(decorateStaff);
    setSearchResults(data)
    setIsLoading(false)
  };


  const handleValueSelect = (val: User) => {
    setSearch('')
    setSearchResults([])
    if (multiple) {
      if (!staff.map(s => JSON.stringify(s)).includes(JSON.stringify(val))) {
        setStaff([...staff, val])
      }
    } else {
      setStaff([val])
    }
  }

  const handleValueRemove = (val: User) => {
    setStaff(staff.filter((v: any) => JSON.stringify(v) !== JSON.stringify(val)))
  }

  // The search result
  const options = searchResults.map((item) => (
    <Combobox.Option value={JSON.stringify(item.value)} key={item.username}>
      <Group gap="sm">
        <Avatar alt={item.label} size={24} mr={5} src={item.image} radius="xl"><IconUser /></Avatar>
        <Text>{item.label} ({item.username})</Text>
      </Group>
    </Combobox.Option>
  ));

  // The selected pills
  const values = Array.isArray(staff)
  ? staff.map((item, i) => {
    const user = decorateStaff(item)
    return (
      <Badge key={user.username} variant='filled' p={0} color="gray.2" size="lg" radius="xl" 
        leftSection={
          <Avatar alt={user.label} size={24} mr={5} src={user.image} radius="xl"><IconUser /></Avatar>
        }
      >
        <Flex gap={4}>
          <Text className="normal-case font-normal text-black text-sm">{user.label}</Text>
          <CloseButton
            onMouseDown={() => {handleValueRemove(staff[i])}}
            variant="transparent"
            size={22}
            iconSize={14}
            tabIndex={-1}
          />
        </Flex>
      </Badge>
    )
  }) : null;


  const dropdown = 
    <Combobox 
      store={combobox} 
      onOptionSubmit={(optionValue: string) => {
        handleValueSelect(JSON.parse(optionValue));
        combobox.closeDropdown();
      }}
      withinPortal={false}
    >
      <Combobox.DropdownTarget>
        <PillsInput 
          pointer 
          rightSection={isLoading ? <Loader size="xs" /> : ''}
          leftSection={multiple ? <IconUsers size={18} /> : <IconUser size={18} />}
        >
          <Pill.Group>
            {values}
            <Combobox.EventsTarget>
              <PillsInput.Field
                onFocus={() => combobox.openDropdown()}
                onClick={() => combobox.openDropdown()}
                onBlur={() => combobox.closeDropdown()}
                value={search}
                placeholder="Search staff"
                onChange={(event) => {
                  loadStaff(event.currentTarget.value)
                }}
                onKeyDown={(event) => {
                  if (event.key === 'Backspace' && search.length === 0) {
                    event.preventDefault();
                    handleValueRemove(staff[staff.length - 1]);
                  }
                }}
                className={(multiple || !staff.length) ? "" : "hidden"}
              />
            </Combobox.EventsTarget>
          </Pill.Group>
        </PillsInput>
      </Combobox.DropdownTarget>

      <Combobox.Dropdown hidden={!options.length}>
        <Combobox.Options>
          {options.length > 0 
            ? options : 
            <Combobox.Empty>Nothing found...</Combobox.Empty>
          }
        </Combobox.Options>
      </Combobox.Dropdown>
    </Combobox>

  const readOnlyValues = Array.isArray(staff)
  ? staff.map((item, i) => {
    const user = decorateStaff(item)
    return (
      <Badge key={user.username} variant='filled' pl={0} color="gray.2" size="lg" radius="xl" leftSection={
        <Avatar alt={user.label} size={24} mr={5} src={user.image} radius="xl"><IconUser /></Avatar>
      }>
        <Flex gap={4}>
          <Text className="normal-case font-normal text-black text-sm">{user.label}</Text>
        </Flex>
      </Badge>
    )
  }) : null;


  return (
    <div>
      <div className="flex items-center gap-2">
        <Text fz="sm" fw={500} c="#212529">{label}</Text>
        {tip && 
          <Tooltip label={tip} multiline w={320} withArrow>
            <div className="flex items-center gap-1 text-blue-600">
              <IconAlertSquare className="size-5" />
              Important info
            </div>
          </Tooltip>
        }
      </div>
      <div className="mt-1">
        {readOnly
          ? readOnlyValues && readOnlyValues.length > 0
            ? readOnlyValues
            : <div className="ml-2 italic">No staff selected</div>
          : dropdown
        }
      </div>
      {sublabel && <Text fz="xs" fw={400} c="#212529">{sublabel}</Text>}
    </div>
  );
};